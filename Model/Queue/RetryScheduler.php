<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Queue;

use Company\SupplierSync\Model\Config;
use Company\SupplierSync\Model\Update\UpdateStore;
use Company\SupplierSync\Model\UpdateStatus;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Moves a locked processing update to retry_pending (with backoff) or failed (attempts exhausted).
 *
 * The attempt was already counted when it was claimed, so a crash before this point still uses up budget.
 */
class RetryScheduler
{
    /** Backoff after claim number 1, 2, 3, 4... (seconds). */
    private const BACKOFF = [1 => 60, 2 => 300, 3 => 900, 4 => 3600];

    /**
     * @param UpdateStore $updateStore
     * @param Config $config
     */
    public function __construct(
        private readonly UpdateStore $updateStore,
        private readonly Config $config
    ) {
    }

    /**
     * Schedule a retry or fail the update. Caller holds the update row lock and checked ownership.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $update Locked row in status processing
     * @param string $errorCode
     * @param string $message Safe message
     * @param bool $permanent Fail now instead of retrying
     * @return string The new status
     */
    public function retryOrFail(
        AdapterInterface $connection,
        array $update,
        string $errorCode,
        string $message,
        bool $permanent = false
    ): string {
        $attempts = (int)$update['attempts'];
        $release = ['claim_token' => null, 'lease_until' => null, 'error_code' => $errorCode, 'safe_error' => $message];
        if ($permanent || $attempts >= $this->config->getMaxAttempts()) {
            $this->updateStore->transition(
                $connection,
                $update,
                UpdateStatus::FAILED,
                $release + ['processed_at' => new \Zend_Db_Expr('NOW()'), 'next_attempt_at' => null],
                $message
            );
            return UpdateStatus::FAILED;
        }
        $delay = $this->backoffSeconds($attempts);
        $this->updateStore->transition(
            $connection,
            $update,
            UpdateStatus::RETRY_PENDING,
            $release + ['next_attempt_at' => new \Zend_Db_Expr(sprintf('NOW() + INTERVAL %d SECOND', $delay))],
            $message
        );
        // Every publication path honours next_publish_at, so no copy of this message is sent before it is due.
        $this->updateStore->rearmOutbox(
            $connection,
            (int)$update['update_id'],
            new \Zend_Db_Expr(sprintf('NOW() + INTERVAL %d SECOND', $delay))
        );
        return UpdateStatus::RETRY_PENDING;
    }

    /**
     * Backoff after the given number of claims.
     *
     * @param int $attempts
     * @return int
     */
    public function backoffSeconds(int $attempts): int
    {
        return self::BACKOFF[$attempts] ?? self::BACKOFF[4];
    }
}
