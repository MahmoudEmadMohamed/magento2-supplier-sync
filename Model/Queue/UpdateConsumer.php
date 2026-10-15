<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Queue;

use Company\SupplierSync\Model\Config;
use Company\SupplierSync\Model\Db\TransactionRunner;
use Company\SupplierSync\Model\Update\SnapshotApplier;
use Company\SupplierSync\Model\Update\UpdateStore;
use Company\SupplierSync\Model\UpdateStatus;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Queue handler for supplier_sync.update.apply. Never throws: every outcome is recorded in the database, then
 * the message is acknowledged (Magento rejects a throwing handler's message without requeueing it).
 *
 * tx1 claims the update exclusively and counts the attempt. tx2 applies it only while the claim is still ours.
 * tx3 (on failure) schedules a retry or fails the update, again only while the claim is still ours.
 */
class UpdateConsumer
{
    /**
     * @param TransactionRunner $transactionRunner
     * @param UpdateStore $updateStore
     * @param SnapshotApplier $snapshotApplier
     * @param RetryScheduler $retryScheduler
     * @param FailureClassifier $failureClassifier
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TransactionRunner $transactionRunner,
        private readonly UpdateStore $updateStore,
        private readonly SnapshotApplier $snapshotApplier,
        private readonly RetryScheduler $retryScheduler,
        private readonly FailureClassifier $failureClassifier,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle one delivery.
     *
     * @param int $updateId
     * @return void
     */
    public function process(int $updateId): void
    {
        try {
            $token = $this->claim($updateId);
        } catch (\Throwable $e) {
            // Nothing was counted or changed; a later delivery or the sweep will pick the update up.
            $this->logger->warning('Update claim failed.', ['update_id' => $updateId, 'exception' => get_class($e)]);
            return;
        }
        if ($token === null) {
            return;
        }
        try {
            $this->apply($updateId, $token);
        } catch (\Throwable $e) {
            $this->handleFailure($updateId, $token, $e);
        }
    }

    /**
     * Tx1: take an exclusive claim if the update is queued or a due retry. Returns the claim token or null.
     *
     * Duplicates arriving during a valid claim, before a retry is due, or after a terminal status do nothing and
     * spend no retry budget.
     *
     * @param int $updateId
     * @return string|null
     */
    private function claim(int $updateId): ?string
    {
        return $this->transactionRunner->run(function (AdapterInterface $connection) use ($updateId): ?string {
            $update = $this->updateStore->lock($connection, $updateId);
            if ($update === null) {
                return null;
            }
            $status = (string)$update['status'];
            $claimable = $status === UpdateStatus::QUEUED
                || ($status === UpdateStatus::RETRY_PENDING && (int)$update['retry_due']);
            if (!$claimable) {
                $this->logger->info('Duplicate or early delivery ignored.', [
                    'update_id' => $updateId,
                    'status' => $status,
                ]);
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $this->updateStore->transition($connection, $update, UpdateStatus::PROCESSING, [
                'claim_token' => $token,
                'attempts' => (int)$update['attempts'] + 1,
                'lease_until' => new \Zend_Db_Expr(
                    sprintf('NOW() + INTERVAL %d SECOND', $this->config->getQueueLeaseSeconds())
                ),
                'last_attempt_at' => new \Zend_Db_Expr('NOW()'),
            ]);
            return $token;
        });
    }

    /**
     * Tx2: apply under the update lock, only while our claim is current, and record the terminal status.
     *
     * @param int $updateId
     * @param string $token
     * @return void
     */
    private function apply(int $updateId, string $token): void
    {
        $outcome = $this->transactionRunner->run(function (AdapterInterface $connection) use ($updateId, $token) {
            $update = $this->updateStore->lock($connection, $updateId);
            if (!$this->owns($update, $token)) {
                return null;
            }
            $result = $this->snapshotApplier->apply($connection, $update);
            $this->updateStore->transition($connection, $update, $result['status'], [
                'claim_token' => null,
                'lease_until' => null,
                'next_attempt_at' => null,
                'processed_at' => new \Zend_Db_Expr('NOW()'),
                'error_code' => $result['error_code'],
                'safe_error' => $result['error'],
            ], $result['error']);
            return $result['status'];
        });
        if ($outcome === null) {
            $this->logger->info('Claim was taken over; this worker made no change.', ['update_id' => $updateId]);
            return;
        }
        $this->logger->info('Update processed.', ['update_id' => $updateId, 'status' => $outcome]);
    }

    /**
     * Tx3: record a failed attempt as retry_pending or failed, if the claim is still ours.
     *
     * @param int $updateId
     * @param string $token
     * @param \Throwable $failure
     * @return void
     */
    private function handleFailure(int $updateId, string $token, \Throwable $failure): void
    {
        $permanent = $this->failureClassifier->classify($failure) === FailureClassifier::PERMANENT;
        $code = $this->failureClassifier->errorCode($failure);
        try {
            $status = $this->transactionRunner->run(
                function (AdapterInterface $connection) use ($updateId, $token, $code, $permanent): ?string {
                    $update = $this->updateStore->lock($connection, $updateId);
                    if (!$this->owns($update, $token)) {
                        return null;
                    }
                    return $this->retryScheduler->retryOrFail(
                        $connection,
                        $update,
                        $code,
                        $permanent ? 'Processing failed permanently.' : 'Processing failed; will retry.',
                        $permanent
                    );
                }
            );
            $this->logger->warning('Update processing failed.', [
                'update_id' => $updateId,
                'error_code' => $code,
                'exception' => get_class($failure),
                'status' => $status ?? 'claim_lost',
            ]);
        } catch (\Throwable $e) {
            // The claim stays in processing; the sweep moves it on when the lease expires.
            $this->logger->error('Could not record update failure; the sweep will recover it.', [
                'update_id' => $updateId,
                'exception' => get_class($e),
            ]);
        }
    }

    /**
     * Whether the locked row is still claimed with this token.
     *
     * @param array<string,mixed>|null $update
     * @param string $token
     * @return bool
     */
    private function owns(?array $update, string $token): bool
    {
        return $update !== null
            && $update['status'] === UpdateStatus::PROCESSING
            && hash_equals((string)$update['claim_token'], $token);
    }
}
