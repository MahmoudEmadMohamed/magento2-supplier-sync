<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Inventory;

use Company\SupplierSync\Model\Decimal;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use LogicException;

/**
 * Row-level access to company_supplier_inventory. Callers own the transaction.
 *
 * Counters are read under FOR UPDATE, computed with bcmath, and written back as absolute values, so no
 * decimal ever passes through SQL floating-point arithmetic.
 */
class InventoryLedger
{
    public const TABLE = 'company_supplier_inventory';
    public const EVENT_TABLE = 'company_supplier_reservation_event';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Find the inventory row id for a supplier, SKU and website without locking.
     *
     * @param AdapterInterface $connection
     * @param int $supplierId
     * @param string $sku
     * @param int $websiteId
     * @return int|null
     */
    public function findId(AdapterInterface $connection, int $supplierId, string $sku, int $websiteId): ?int
    {
        $id = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['inventory_id'])
                ->where('supplier_id = ?', $supplierId)
                ->where('sku = ?', $sku)
                ->where('website_id = ?', $websiteId)
        );
        return $id === false ? null : (int)$id;
    }

    /**
     * Lock inventory rows one by one in ascending id order (the global lock order) and return them by id.
     *
     * @param AdapterInterface $connection
     * @param int[] $inventoryIds
     * @return array<int,array<string,mixed>>
     */
    public function lockRows(AdapterInterface $connection, array $inventoryIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $inventoryIds)));
        sort($ids);
        $rows = [];
        foreach ($ids as $id) {
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->resource->getTableName(self::TABLE))
                    ->where('inventory_id = ?', $id)
                    ->forUpdate(true)
            );
            if (!$row) {
                throw new LogicException(sprintf('Inventory row %d does not exist.', $id));
            }
            $rows[$id] = $row;
        }
        return $rows;
    }

    /**
     * Available quantity: max(0, quantity - reserved_qty - fulfilled_pending_qty).
     *
     * @param array<string,mixed> $row
     * @return string
     */
    public function getAvailable(array $row): string
    {
        $free = Decimal::sub((string)$row['quantity'], (string)$row['reserved_qty']);
        return Decimal::clampZero(Decimal::sub($free, (string)$row['fulfilled_pending_qty']));
    }

    /**
     * Shortage: max(0, reserved_qty + fulfilled_pending_qty - quantity).
     *
     * @param array<string,mixed> $row
     * @return string
     */
    public function getShortage(array $row): string
    {
        $committed = Decimal::add((string)$row['reserved_qty'], (string)$row['fulfilled_pending_qty']);
        return Decimal::clampZero(Decimal::sub($committed, (string)$row['quantity']));
    }

    /**
     * Change reserved_qty and fulfilled_pending_qty of a locked row by the given deltas.
     *
     * The row array is updated in place so later operations in the same transaction see the new values.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $row Locked row, updated in place
     * @param string $reservedDelta
     * @param string $fulfilledPendingDelta
     * @return void
     * @throws LogicException if a counter would go negative (a ledger invariant is broken)
     */
    public function adjustCounters(
        AdapterInterface $connection,
        array &$row,
        string $reservedDelta,
        string $fulfilledPendingDelta = '0'
    ): void {
        $reserved = Decimal::add((string)$row['reserved_qty'], $reservedDelta);
        $fulfilledPending = Decimal::add((string)$row['fulfilled_pending_qty'], $fulfilledPendingDelta);
        if (Decimal::cmp($reserved, '0') < 0 || Decimal::cmp($fulfilledPending, '0') < 0) {
            throw new LogicException(sprintf('Inventory %d counters would go negative.', $row['inventory_id']));
        }
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            ['reserved_qty' => $reserved, 'fulfilled_pending_qty' => $fulfilledPending],
            ['inventory_id = ?' => (int)$row['inventory_id']]
        );
        $row['reserved_qty'] = $reserved;
        $row['fulfilled_pending_qty'] = $fulfilledPending;
    }

    /**
     * Append a ledger event while holding the inventory row lock.
     *
     * Holding the lock makes event_id order equal commit order for one inventory row; the snapshot
     * watermark depends on this.
     *
     * @param AdapterInterface $connection
     * @param int $reservationId
     * @param int $inventoryId
     * @param string $type reserve, fulfil or release
     * @param string $qty
     * @param string $source
     * @return void
     */
    public function appendEvent(
        AdapterInterface $connection,
        int $reservationId,
        int $inventoryId,
        string $type,
        string $qty,
        string $source
    ): void {
        $connection->insert($this->resource->getTableName(self::EVENT_TABLE), [
            'reservation_id' => $reservationId,
            'inventory_id' => $inventoryId,
            'type' => $type,
            'qty' => $qty,
            'source' => $source,
        ]);
    }
}
