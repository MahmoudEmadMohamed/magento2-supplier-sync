<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Db;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DeadlockException;
use LogicException;

/**
 * Runs a unit of work in a module-owned transaction, retrying the whole unit on deadlock.
 *
 * The unit must be safe to re-run from the start: it re-reads everything it needs under its own locks.
 * Nesting is refused, because a nested "transaction" in Magento is only a savepoint-less counter: a retry or
 * rollback inside it would not undo the caller's work, and its locks would be held for the caller's duration.
 */
class TransactionRunner
{
    public const MAX_DEADLOCK_ATTEMPTS = 3;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Run $work(AdapterInterface $connection) in its own transaction and return its result.
     *
     * @template T
     * @param callable(AdapterInterface):T $work
     * @return T
     * @throws \Throwable whatever $work throws, after rollback
     */
    public function run(callable $work)
    {
        $connection = $this->resource->getConnection();
        if ($connection->getTransactionLevel() > 0) {
            throw new LogicException('Supplier sync transactions must not run inside another transaction.');
        }
        for ($attempt = 1;; $attempt++) {
            $connection->beginTransaction();
            try {
                $result = $work($connection);
                $connection->commit();
                return $result;
            } catch (\Throwable $e) {
                $connection->rollBack();
                if ($attempt < self::MAX_DEADLOCK_ATTEMPTS && $this->isDeadlock($e)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Whether the exception (or one it wraps) is an InnoDB deadlock.
     *
     * @param \Throwable $e
     * @return bool
     */
    private function isDeadlock(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof DeadlockException) {
                return true;
            }
        }
        return false;
    }
}
