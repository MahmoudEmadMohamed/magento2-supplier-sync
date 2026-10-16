<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Queue;

use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\Exception\LocalizedException;

/**
 * The single place that decides whether a processing failure is worth retrying.
 *
 * Expected business outcomes (stale, rejected) are not exceptions and never reach this class.
 */
class FailureClassifier
{
    public const RETRYABLE = 'retryable';
    public const PERMANENT = 'permanent';

    /** MySQL client errors meaning the connection was lost: server gone away, lost connection. */
    private const CONNECTION_LOST_CODES = [2006, 2013];

    /**
     * Classify a failure.
     *
     * Transient database conditions are retryable. Programming and domain errors are permanent: retrying them
     * would fail the same way. Anything unrecognised is retried, bounded by the attempt limit.
     *
     * @param \Throwable $e
     * @return string
     */
    public function classify(\Throwable $e): string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof DeadlockException
                || $current instanceof LockWaitException
                || $current instanceof ConnectionException
            ) {
                return self::RETRYABLE;
            }
            if ($this->isConnectionLost($current)) {
                return self::RETRYABLE;
            }
        }
        if ($e instanceof \LogicException || $e instanceof \TypeError || $e instanceof LocalizedException) {
            return self::PERMANENT;
        }
        return self::RETRYABLE;
    }

    /**
     * Whether this is a PDO error meaning the connection was lost.
     *
     * @param \Throwable $e
     * @return bool
     */
    private function isConnectionLost(\Throwable $e): bool
    {
        return $e instanceof \PDOException
            && in_array((int)($e->errorInfo[1] ?? 0), self::CONNECTION_LOST_CODES, true);
    }

    /**
     * Short, safe error code for the update row and logs (never the exception message).
     *
     * @param \Throwable $e
     * @return string
     */
    public function errorCode(\Throwable $e): string
    {
        return match (true) {
            $e instanceof DeadlockException => 'db_deadlock',
            $e instanceof LockWaitException => 'db_lock_wait_timeout',
            $e instanceof ConnectionException, $this->isConnectionLost($e) => 'db_connection',
            $e instanceof \PDOException => 'db_error',
            $this->classify($e) === self::PERMANENT => 'processing_error',
            default => 'transient_error',
        };
    }
}
