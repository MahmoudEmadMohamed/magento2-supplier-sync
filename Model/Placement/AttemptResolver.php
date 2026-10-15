<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Placement;

use Company\SupplierSync\Model\AttemptStatus;
use Company\SupplierSync\Model\Db\TransactionRunner;
use Company\SupplierSync\Model\Reservation\ReservationLedger;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * The only class that changes a placement attempt after creation. Every method locks the attempt row first.
 *
 * Decisions are made from the attempt's status under that lock, never from timeouts or order lookups:
 * - started: payment never began, and the pre-payment fence now blocks it, so the hold can be released;
 * - paying: payment may be in flight, so the hold is kept (detached on recovery);
 * - order_saved: the order exists, so the hold is linked.
 */
class AttemptResolver
{
    /**
     * @param TransactionRunner $transactionRunner
     * @param AttemptStore $attemptStore
     * @param ReservationLedger $reservationLedger
     * @param ResourceConnection $resource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TransactionRunner $transactionRunner,
        private readonly AttemptStore $attemptStore,
        private readonly ReservationLedger $reservationLedger,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Link an order_saved attempt's reservations to its order items and mark it completed.
     *
     * @param int $attemptId
     * @return int|null The order id,or null if the attempt has no saved order
     */
    public function link(int $attemptId): ?int
    {
        return $this->transactionRunner->run(function (AdapterInterface $connection) use ($attemptId): ?int {
            $attempt = $this->attemptStore->lock($connection, $attemptId);
            return $attempt === null ? null : $this->linkLocked($connection, $attempt);
        });
    }

    /**
     * Decide what happens to an attempt after proceed() threw.
     *
     * If the order committed (order_saved), the hold is linked; the exception came from after the commit.
     * Otherwise the attempt is failed: the pre-commit fence will reject any late commit for it.
     *
     * @param int $attemptId
     * @return int|null The order id if the order exists
     */
    public function resolveAfterException(int $attemptId): ?int
    {
        return $this->transactionRunner->run(function (AdapterInterface $connection) use ($attemptId): ?int {
            $attempt = $this->attemptStore->lock($connection, $attemptId);
            if ($attempt === null) {
                return null;
            }
            if (in_array($attempt['status'], [AttemptStatus::ORDER_SAVED, AttemptStatus::COMPLETED], true)) {
                return $this->linkLocked($connection, $attempt);
            }
            $this->failLocked($connection, $attempt, 'placement_failed');
            return null;
        });
    }

    /**
     * Recover an attempt whose lease expired (crashed or hung worker), by status.
     *
     * @param int $attemptId
     * @return string What happened:released,detached,linked or none
     */
    public function recover(int $attemptId): string
    {
        return $this->transactionRunner->run(function (AdapterInterface $connection) use ($attemptId): string {
            $attempt = $this->attemptStore->lock($connection, $attemptId);
            return $attempt === null ? 'none' : $this->recoverLocked($connection, $attempt);
        });
    }

    /**
     * Recover a locked attempt inside the caller's transaction. Only acts on expired leases.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $attempt Locked row
     * @return string
     */
    public function recoverLocked(AdapterInterface $connection, array $attempt): string
    {
        $attemptId = (int)$attempt['attempt_id'];
        $expired = (bool)$connection->fetchOne('SELECT ? < NOW()', [$attempt['lease_until']]);
        switch ($attempt['status']) {
            case AttemptStatus::ORDER_SAVED:
                $this->linkLocked($connection, $attempt);
                return 'linked';
            case AttemptStatus::STARTED:
                if (!$expired) {
                    return 'none';
                }
                $this->failLocked($connection, $attempt, 'recovery');
                return 'released';
            case AttemptStatus::PAYING:
                if (!$expired || $attempt['detached_at'] !== null) {
                    return 'none';
                }
                $this->reservationLedger->detachAttempt($connection, $attemptId);
                $this->attemptStore->markDetached($connection, $attemptId);
                $this->logger->warning('Placement attempt expired while paying; hold kept and detached.', [
                    'attempt_id' => $attemptId,
                    'quote_id' => (int)$attempt['quote_id'],
                ]);
                return 'detached';
            default:
                return 'none';
        }
    }

    /**
     * Operator release of a detached paying attempt (CLI attempt:resolve --release).
     *
     * After this, a late commit by the original worker is rejected by the pre-commit fence.
     *
     * @param int $attemptId
     * @return bool
     */
    public function operatorRelease(int $attemptId): bool
    {
        return $this->transactionRunner->run(function (AdapterInterface $connection) use ($attemptId): bool {
            $attempt = $this->attemptStore->lock($connection, $attemptId);
            if ($attempt === null || $attempt['status'] !== AttemptStatus::PAYING || $attempt['detached_at'] === null) {
                return false;
            }
            return $this->failLocked($connection, $attempt, 'operator_release');
        });
    }

    /**
     * Link a locked attempt whose order exists.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $attempt
     * @return int|null
     */
    private function linkLocked(AdapterInterface $connection, array $attempt): ?int
    {
        if (!in_array($attempt['status'], [AttemptStatus::ORDER_SAVED, AttemptStatus::COMPLETED], true)
            || $attempt['order_id'] === null
        ) {
            return null;
        }
        $orderId = (int)$attempt['order_id'];
        $items = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName('sales_order_item'),
                    ['item_id', 'quote_item_id', 'parent_item_id']
                )
                ->where('order_id = ?', $orderId)
        );
        $map = [];
        foreach ($items as $item) {
            if ($item['quote_item_id'] === null) {
                continue;
            }
            $map[(int)$item['quote_item_id']] = [
                'order_item_id' => (int)$item['item_id'],
                // Configurable children carry no shipped or cancelled quantities; their parent does.
                'qty_source_item_id' => (int)($item['parent_item_id'] ?? $item['item_id']),
            ];
        }
        $this->reservationLedger->linkAttempt($connection, (int)$attempt['attempt_id'], $orderId, $map);
        if ($attempt['status'] === AttemptStatus::ORDER_SAVED) {
            $this->attemptStore->transition($connection, $attempt, AttemptStatus::COMPLETED);
        }
        return $orderId;
    }

    /**
     * Release an attempt's holds and mark it failed. No-op if it is already failed.
     *
     * Refuses attempts whose order exists (order_saved, completed): those are linked, never released.
     *
     * @param int $attemptId
     * @param string $source
     * @return bool Whether the attempt is failed afterwards
     */
    public function fail(int $attemptId, string $source): bool
    {
        return $this->transactionRunner->run(function (AdapterInterface $connection) use ($attemptId, $source): bool {
            $attempt = $this->attemptStore->lock($connection, $attemptId);
            if ($attempt === null) {
                return false;
            }
            return $this->failLocked($connection, $attempt, $source);
        });
    }

    /**
     * Fail a locked attempt inside the caller's transaction.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $attempt
     * @param string $source
     * @return bool
     */
    public function failLocked(AdapterInterface $connection, array $attempt, string $source): bool
    {
        $status = (string)$attempt['status'];
        if ($status === AttemptStatus::FAILED) {
            return true;
        }
        if (!in_array($status, [AttemptStatus::STARTED, AttemptStatus::PAYING], true)) {
            return false;
        }
        $this->reservationLedger->releaseAttempt($connection, (int)$attempt['attempt_id'], $source);
        $this->attemptStore->transition($connection, $attempt, AttemptStatus::FAILED);
        $this->logger->info('Placement attempt failed; holds released.', [
            'attempt_id' => (int)$attempt['attempt_id'],
            'from_status' => $status,
            'source' => $source,
        ]);
        return true;
    }
}
