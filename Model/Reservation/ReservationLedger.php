<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Reservation;

use Company\SupplierSync\Exception\InsufficientSupplierStockException;
use Company\SupplierSync\Model\Decimal;
use Company\SupplierSync\Model\Inventory\InventoryLedger;
use Company\SupplierSync\Model\ReservationStatus;
use Company\SupplierSync\Model\StateTransitionValidator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Reserve, release, fulfil and link supplier stock. Callers own the transaction.
 *
 * Lock order (global): placement attempt → inventory rows (ascending id) → reservation rows (ascending id).
 * Callers that change an attempt lock it before calling in; every method here locks inventory before
 * reservations. Releases and fulfilments are deltas against persisted counters, so repeating a call is a no-op.
 */
class ReservationLedger
{
    public const TABLE = 'company_supplier_reservation';

    private const TERMINAL_ORDER_STATES = ['closed', 'canceled', 'complete'];

    private const WRITABLE_COLUMNS = [
        'qty_fulfilled', 'qty_released', 'status', 'active_quote_item_id',
        'order_id', 'order_item_id', 'qty_source_item_id',
    ];

    /**
     * @param ResourceConnection $resource
     * @param InventoryLedger $inventoryLedger
     * @param StateTransitionValidator $transitions
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly InventoryLedger $inventoryLedger,
        private readonly StateTransitionValidator $transitions
    ) {
    }

    /**
     * Reserve every line for an attempt, all or nothing.
     *
     * Lines for the same inventory row are checked as one aggregated quantity.
     *
     * @param AdapterInterface $connection
     * @param int $attemptId Must be locked by the caller and in status started
     * @param ReservationLine[] $lines
     * @return void
     * @throws InsufficientSupplierStockException
     * @throws LocalizedException when a quote item is already held by another attempt
     */
    public function reserve(AdapterInterface $connection, int $attemptId, array $lines): void
    {
        $required = [];
        $inventoryIdByLine = [];
        foreach ($lines as $index => $line) {
            $inventoryId = $this->inventoryLedger->findId($connection, $line->supplierId, $line->sku, $line->websiteId);
            if ($inventoryId === null) {
                throw $this->insufficient($line->sku);
            }
            $inventoryIdByLine[$index] = $inventoryId;
            $required[$inventoryId] = Decimal::add($required[$inventoryId] ?? '0', $line->qty);
        }

        $rows = $this->inventoryLedger->lockRows($connection, array_keys($required));
        foreach ($rows as $inventoryId => $row) {
            if (!$this->isSupplierActive($connection, (int)$row['supplier_id'], (int)$row['website_id'])) {
                throw $this->insufficient((string)$row['sku']);
            }
            if (Decimal::cmp($this->inventoryLedger->getAvailable($row), $required[$inventoryId]) < 0) {
                throw $this->insufficient((string)$row['sku']);
            }
        }

        foreach ($lines as $index => $line) {
            $inventoryId = $inventoryIdByLine[$index];
            $this->inventoryLedger->adjustCounters($connection, $rows[$inventoryId], $line->qty);
            try {
                $connection->insert($this->resource->getTableName(self::TABLE), [
                    'attempt_id' => $attemptId,
                    'inventory_id' => $inventoryId,
                    'quote_item_id' => $line->quoteItemId,
                    'active_quote_item_id' => $line->quoteItemId,
                    'sku' => $line->sku,
                    'website_id' => $line->websiteId,
                    'qty_reserved' => $line->qty,
                    'status' => ReservationStatus::PENDING_PLACEMENT,
                ]);
            } catch (DuplicateException $e) {
                throw new LocalizedException(__('This cart is already being checked out. Please try again.'), $e);
            }
            $reservationId = (int)$connection->lastInsertId($this->resource->getTableName(self::TABLE));
            $this->inventoryLedger->appendEvent(
                $connection,
                $reservationId,
                $inventoryId,
                'reserve',
                $line->qty,
                'placement'
            );
        }
    }

    /**
     * Release everything still held by an attempt and clear its active quote item keys.
     *
     * @param AdapterInterface $connection
     * @param int $attemptId Must be locked by the caller
     * @param string $source
     * @return void
     */
    public function releaseAttempt(AdapterInterface $connection, int $attemptId, string $source): void
    {
        [$inventory, $reservations] = $this->lockByAttempt($connection, $attemptId);
        foreach ($reservations as $reservation) {
            $remainder = $this->openQty($reservation);
            $row = &$inventory[(int)$reservation['inventory_id']];
            if (Decimal::isPositive($remainder)) {
                $this->release($connection, $reservation, $row, $remainder, $source);
            } else {
                $this->write($connection, $reservation, []);
            }
            unset($row);
        }
    }

    /**
     * Keep the holds of an attempt but stop them blocking a new attempt for the same quote items.
     *
     * @param AdapterInterface $connection
     * @param int $attemptId Must be locked by the caller
     * @return void
     */
    public function detachAttempt(AdapterInterface $connection, int $attemptId): void
    {
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            ['active_quote_item_id' => null],
            ['attempt_id = ?' => $attemptId]
        );
    }

    /**
     * Link an attempt's reservations to the order items created for its quote items.
     *
     * A reservation whose quote item has no order item is released: the order does not contain that line.
     *
     * @param AdapterInterface $connection
     * @param int $attemptId Must be locked by the caller
     * @param int $orderId
     * @param array<int,array{order_item_id:int,qty_source_item_id:int}> $orderItemsByQuoteItem
     * @return void
     */
    public function linkAttempt(
        AdapterInterface $connection,
        int $attemptId,
        int $orderId,
        array $orderItemsByQuoteItem
    ): void {
        [$inventory, $reservations] = $this->lockByAttempt($connection, $attemptId);
        foreach ($reservations as $reservation) {
            if ($reservation['order_item_id'] !== null) {
                continue;
            }
            $match = $orderItemsByQuoteItem[(int)$reservation['quote_item_id']] ?? null;
            if ($match === null) {
                $row = &$inventory[(int)$reservation['inventory_id']];
                $remainder = $this->openQty($reservation);
                if (Decimal::isPositive($remainder)) {
                    $this->release($connection, $reservation, $row, $remainder, 'order_item_missing');
                }
                unset($row);
                continue;
            }
            $this->write($connection, $reservation, [
                'order_id' => $orderId,
                'order_item_id' => $match['order_item_id'],
                'qty_source_item_id' => $match['qty_source_item_id'],
            ]);
        }
    }

    /**
     * Bring an order's linked reservations in line with its persisted item quantities and state.
     *
     * Fulfil first (from qty_shipped), then release newly cancelled quantity (from qty_canceled), then release
     * any remainder if the order is closed, canceled or complete. Re-running with unchanged data does nothing.
     *
     * @param AdapterInterface $connection
     * @param int $orderId
     * @return int Number of reservations changed
     */
    public function reconcileOrder(AdapterInterface $connection, int $orderId): int
    {
        [$inventory, $reservations] = $this->lockByOrder($connection, $orderId);
        if (!$reservations) {
            return 0;
        }
        $state = (string)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('sales_order'), ['state'])
                ->where('entity_id = ?', $orderId)
        );
        $sourceIds = array_map(static fn (array $r): int => (int)$r['qty_source_item_id'], $reservations);
        $items = $connection->fetchAssoc(
            $connection->select()
                ->from($this->resource->getTableName('sales_order_item'), ['item_id', 'qty_shipped', 'qty_canceled'])
                ->where('item_id IN (?)', $sourceIds)
        );

        $changed = 0;
        foreach ($reservations as $reservation) {
            if ($reservation['status'] === ReservationStatus::CLOSED) {
                continue;
            }
            $item = $items[(int)$reservation['qty_source_item_id']] ?? ['qty_shipped' => '0', 'qty_canceled' => '0'];
            $row = &$inventory[(int)$reservation['inventory_id']];
            $before = $reservation;

            $fulfil = Decimal::min(
                Decimal::sub(Decimal::fromNumber($item['qty_shipped']), (string)$reservation['qty_fulfilled']),
                $this->openQty($reservation)
            );
            if (Decimal::isPositive($fulfil)) {
                $reservation = $this->fulfil($connection, $reservation, $row, $fulfil);
            }
            $cancel = Decimal::min(
                Decimal::sub(Decimal::fromNumber($item['qty_canceled']), (string)$reservation['qty_released']),
                $this->openQty($reservation)
            );
            if (Decimal::isPositive($cancel)) {
                $reservation = $this->release($connection, $reservation, $row, $cancel, 'order_cancel');
            }
            $remainder = $this->openQty($reservation);
            if (in_array($state, self::TERMINAL_ORDER_STATES, true) && Decimal::isPositive($remainder)) {
                $reservation = $this->release($connection, $reservation, $row, $remainder, 'order_' . $state);
            }
            unset($row);
            if ($reservation !== $before) {
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * Quantity still held: qty_reserved - qty_fulfilled - qty_released.
     *
     * @param array<string,mixed> $reservation
     * @return string
     */
    public function openQty(array $reservation): string
    {
        return Decimal::sub(
            Decimal::sub((string)$reservation['qty_reserved'], (string)$reservation['qty_fulfilled']),
            (string)$reservation['qty_released']
        );
    }

    /**
     * Move a quantity from reserved to fulfilled-pending and append a fulfil event.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $reservation Locked reservation row
     * @param array<string,mixed> $inventoryRow Locked inventory row, updated in place
     * @param string $qty
     * @return array<string,mixed> The updated reservation row
     */
    private function fulfil(AdapterInterface $connection, array $reservation, array &$inventoryRow, string $qty): array
    {
        $this->inventoryLedger->adjustCounters($connection, $inventoryRow, Decimal::sub('0', $qty), $qty);
        $reservation = $this->write($connection, $reservation, [
            'qty_fulfilled' => Decimal::add((string)$reservation['qty_fulfilled'], $qty),
        ]);
        $this->inventoryLedger->appendEvent(
            $connection,
            (int)$reservation['reservation_id'],
            (int)$reservation['inventory_id'],
            'fulfil',
            $qty,
            'shipment'
        );
        return $reservation;
    }

    /**
     * Release a quantity back to availability and append a release event.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $reservation Locked reservation row
     * @param array<string,mixed> $inventoryRow Locked inventory row, updated in place
     * @param string $qty
     * @param string $source
     * @return array<string,mixed> The updated reservation row
     */
    private function release(
        AdapterInterface $connection,
        array $reservation,
        array &$inventoryRow,
        string $qty,
        string $source
    ): array {
        $this->inventoryLedger->adjustCounters($connection, $inventoryRow, Decimal::sub('0', $qty));
        $reservation = $this->write($connection, $reservation, [
            'qty_released' => Decimal::add((string)$reservation['qty_released'], $qty),
        ]);
        $this->inventoryLedger->appendEvent(
            $connection,
            (int)$reservation['reservation_id'],
            (int)$reservation['inventory_id'],
            'release',
            $qty,
            $source
        );
        return $reservation;
    }

    /**
     * Write changed columns plus the derived status and active key, validating the status transition.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $reservation
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function write(AdapterInterface $connection, array $reservation, array $changes): array
    {
        $next = array_merge($reservation, $changes);
        $next['status'] = ReservationStatus::derive(
            (string)$next['qty_reserved'],
            (string)$next['qty_fulfilled'],
            (string)$next['qty_released'],
            $next['order_item_id'] !== null
        );
        $this->transitions->assert(
            StateTransitionValidator::RESERVATION,
            (string)$reservation['status'],
            $next['status']
        );
        if ($next['status'] === ReservationStatus::CLOSED) {
            $next['active_quote_item_id'] = null;
        }
        $diff = [];
        foreach (self::WRITABLE_COLUMNS as $key) {
            $old = $reservation[$key] ?? null;
            $new = $next[$key] ?? null;
            if ($old === null ? $new !== null : ($new === null || (string)$old !== (string)$new)) {
                $diff[$key] = $new;
            }
        }
        if ($diff) {
            $connection->update(
                $this->resource->getTableName(self::TABLE),
                $diff,
                ['reservation_id = ?' => (int)$reservation['reservation_id']]
            );
        }
        return $next;
    }

    /**
     * Lock the inventory rows, then the reservation rows, of an attempt.
     *
     * @param AdapterInterface $connection
     * @param int $attemptId
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function lockByAttempt(AdapterInterface $connection, int $attemptId): array
    {
        return $this->lockReservations($connection, 'attempt_id = ?', $attemptId);
    }

    /**
     * Lock the inventory rows, then the reservation rows, linked to an order.
     *
     * @param AdapterInterface $connection
     * @param int $orderId
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function lockByOrder(AdapterInterface $connection, int $orderId): array
    {
        return $this->lockReservations($connection, 'order_id = ?', $orderId);
    }

    /**
     * Find reservations without locking, lock their inventory rows, then lock the reservations by id.
     *
     * A reservation's inventory_id never changes, so the unlocked read is safe for choosing which rows to lock.
     *
     * @param AdapterInterface $connection
     * @param string $condition
     * @param int $value
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function lockReservations(AdapterInterface $connection, string $condition, int $value): array
    {
        $table = $this->resource->getTableName(self::TABLE);
        $pairs = $connection->fetchPairs(
            $connection->select()->from($table, ['reservation_id', 'inventory_id'])->where($condition, $value)
        );
        if (!$pairs) {
            return [[], []];
        }
        $inventory = $this->inventoryLedger->lockRows($connection, array_values($pairs));
        $ids = array_map('intval', array_keys($pairs));
        sort($ids);
        $reservations = [];
        foreach ($ids as $id) {
            $reservations[$id] = $connection->fetchRow(
                $connection->select()->from($table)->where('reservation_id = ?', $id)->forUpdate(true)
            );
        }
        return [$inventory, $reservations];
    }

    /**
     * Whether the supplier is active and enabled for the website.
     *
     * @param AdapterInterface $connection
     * @param int $supplierId
     * @param int $websiteId
     * @return bool
     */
    private function isSupplierActive(AdapterInterface $connection, int $supplierId, int $websiteId): bool
    {
        return (bool)$connection->fetchOne(
            $connection->select()
                ->from(['s' => $this->resource->getTableName('company_supplier')], [])
                ->join(
                    ['sw' => $this->resource->getTableName('company_supplier_website')],
                    'sw.supplier_id = s.supplier_id',
                    ['active' => new \Zend_Db_Expr('s.is_active = 1 AND sw.is_active = 1')]
                )
                ->where('s.supplier_id = ?', $supplierId)
                ->where('sw.website_id = ?', $websiteId)
        );
    }

    /**
     * Customer-safe insufficient stock error.
     *
     * @param string $sku
     * @return InsufficientSupplierStockException
     */
    private function insufficient(string $sku): InsufficientSupplierStockException
    {
        return new InsufficientSupplierStockException(
            __('The requested quantity for "%1" is not available from the supplier.', $sku)
        );
    }
}
