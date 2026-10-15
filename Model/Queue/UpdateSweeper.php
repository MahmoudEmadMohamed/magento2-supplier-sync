<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Queue;

use Company\SupplierSync\Model\Db\TransactionRunner;
use Company\SupplierSync\Model\Update\UpdateStore;
use Company\SupplierSync\Model\UpdateStatus;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Safety net for crashed workers and lost messages.
 *
 * Ownership rule: a candidate row is locked with SKIP LOCKED, so a row held by a worker's apply transaction is
 * skipped rather than waited on, and its status, lease and outbox state are re-checked under the lock before any
 * write. An expired lease allows a recovery attempt; it never bypasses the row lock.
 */
class UpdateSweeper
{
    /** Republish a possibly lost message this long after its last publication, by publication count. */
    private const REPUBLISH_AFTER = 'CASE WHEN o.attempts <= 1 THEN 600 WHEN o.attempts = 2 THEN 1800 ELSE 7200 END';

    /**
     * @param TransactionRunner $transactionRunner
     * @param ResourceConnection $resource
     * @param UpdateStore $updateStore
     * @param RetryScheduler $retryScheduler
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TransactionRunner $transactionRunner,
        private readonly ResourceConnection $resource,
        private readonly UpdateStore $updateStore,
        private readonly RetryScheduler $retryScheduler,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run both sweeps. Returns counts by action.
     *
     * @param int $limit
     * @return array{expired:int,republished:int,skipped:int}
     */
    public function sweep(int $limit = 100): array
    {
        $result = ['expired' => 0, 'republished' => 0, 'skipped' => 0];
        foreach ($this->findExpiredClaims($limit) as $updateId) {
            $done = $this->transactionRunner->run(function (AdapterInterface $connection) use ($updateId): bool {
                $update = $this->updateStore->lockSkipLocked($connection, $updateId);
                $expired = $update !== null
                    && $update['status'] === UpdateStatus::PROCESSING
                    && (int)$update['lease_expired'];
                if (!$expired) {
                    return false;
                }
                $status = $this->retryScheduler->retryOrFail(
                    $connection,
                    $update,
                    'lease_expired',
                    'Processing lease expired (worker interrupted).'
                );
                $this->logger->warning('Expired processing claim recovered.', [
                    'update_id' => $updateId,
                    'status' => $status,
                ]);
                return true;
            });
            $result[$done ? 'expired' : 'skipped']++;
        }
        foreach ($this->findPossiblyLost($limit) as $updateId) {
            $done = $this->transactionRunner->run(function (AdapterInterface $connection) use ($updateId): bool {
                $update = $this->updateStore->lockSkipLocked($connection, $updateId);
                $waiting = $update !== null && ($update['status'] === UpdateStatus::QUEUED
                    || ($update['status'] === UpdateStatus::RETRY_PENDING && (int)$update['retry_due']));
                if (!$waiting) {
                    return false;
                }
                $this->updateStore->rearmOutbox($connection, $updateId, null);
                return true;
            });
            $result[$done ? 'republished' : 'skipped']++;
        }
        return $result;
    }

    /**
     * Candidates: processing with an expired lease (plain read; re-checked under lock).
     *
     * @param int $limit
     * @return int[]
     */
    private function findExpiredClaims(int $limit): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName(UpdateStore::TABLE), ['update_id'])
                ->where('status = ?', UpdateStatus::PROCESSING)
                ->where('lease_until < NOW()')
                ->order('update_id')
                ->limit($limit)
        ));
    }

    /**
     * Candidates: waiting to be claimed, but published long enough ago that the message may be lost.
     *
     * @param int $limit
     * @return int[]
     */
    private function findPossiblyLost(int $limit): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from(['u' => $this->resource->getTableName(UpdateStore::TABLE)], ['update_id'])
                ->join(
                    ['o' => $this->resource->getTableName(UpdateStore::OUTBOX_TABLE)],
                    'o.update_id = u.update_id',
                    []
                )
                ->where('o.published_at IS NOT NULL')
                ->where(
                    'u.status = ? OR (u.status = \'' . UpdateStatus::RETRY_PENDING . '\''
                    . ' AND (u.next_attempt_at IS NULL OR u.next_attempt_at <= NOW()))',
                    UpdateStatus::QUEUED
                )
                ->where('o.published_at < NOW() - INTERVAL (' . self::REPUBLISH_AFTER . ') SECOND')
                ->order('u.update_id')
                ->limit($limit)
        ));
    }
}
