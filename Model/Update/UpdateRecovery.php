<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Update;

use Company\SupplierSync\Model\Db\TransactionRunner;
use Company\SupplierSync\Model\Queue\UpdatePublisher;
use Company\SupplierSync\Model\UpdateStatus;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Operator retry of a failed (or, with force, rejected) update.
 */
class UpdateRecovery
{
    /**
     * @param TransactionRunner $transactionRunner
     * @param UpdateStore $updateStore
     * @param UpdatePublisher $publisher
     */
    public function __construct(
        private readonly TransactionRunner $transactionRunner,
        private readonly UpdateStore $updateStore,
        private readonly UpdatePublisher $publisher
    ) {
    }

    /**
     * Reset attempts and error, requeue, and publish.
     *
     * @param int $updateId
     * @param bool $force Also allow rejected updates (after fixing the external cause)
     * @return void
     * @throws LocalizedException
     */
    public function retry(int $updateId, bool $force): void
    {
        $this->transactionRunner->run(function (AdapterInterface $connection) use ($updateId, $force): void {
            $update = $this->updateStore->lock($connection, $updateId);
            if ($update === null) {
                throw new LocalizedException(__('Stock update %1 does not exist.', $updateId));
            }
            $allowed = $update['status'] === UpdateStatus::FAILED
                || ($force && $update['status'] === UpdateStatus::REJECTED);
            if (!$allowed) {
                throw new LocalizedException(__(
                    'Only failed updates can be retried (rejected ones with --force); update %1 is %2.',
                    $updateId,
                    $update['status']
                ));
            }
            $this->updateStore->transition($connection, $update, UpdateStatus::QUEUED, [
                'attempts' => 0,
                'claim_token' => null,
                'lease_until' => null,
                'next_attempt_at' => null,
                'processed_at' => null,
                'error_code' => null,
                'safe_error' => null,
            ], $force ? 'Operator retry (forced).' : 'Operator retry.');
            $this->updateStore->rearmOutbox($connection, $updateId, null);
        });
        $this->publisher->publishAccepted($updateId);
    }
}
