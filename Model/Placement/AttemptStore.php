<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Placement;

use Company\SupplierSync\Model\AttemptStatus;
use Company\SupplierSync\Model\Config;
use Company\SupplierSync\Model\StateTransitionValidator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Row access to company_supplier_placement_attempt. Status changes go through AttemptResolver.
 */
class AttemptStore
{
    public const TABLE = 'company_supplier_placement_attempt';

    /**
     * @param ResourceConnection $resource
     * @param Config $config
     * @param StateTransitionValidator $transitions
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly StateTransitionValidator $transitions
    ) {
    }

    /**
     * Insert a started attempt and return its id. Runs in autocommit: the row must be visible to recovery.
     *
     * @param int $quoteId
     * @param string|null $reservedOrderId
     * @return int
     */
    public function create(int $quoteId, ?string $reservedOrderId): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->insert($table, [
            'quote_id' => $quoteId,
            'reserved_order_id' => $reservedOrderId,
            'status' => AttemptStatus::STARTED,
            'lease_until' => new \Zend_Db_Expr(
                sprintf('NOW() + INTERVAL %d SECOND', $this->config->getPlacementLeaseSeconds())
            ),
        ]);
        return (int)$connection->lastInsertId($table);
    }

    /**
     * Lock and return an attempt row, or null if it does not exist.
     *
     * @param AdapterInterface $connection
     * @param int $attemptId
     * @return array<string,mixed>|null
     */
    public function lock(AdapterInterface $connection, int $attemptId): ?array
    {
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('attempt_id = ?', $attemptId)
                ->forUpdate(true)
        );
        return $row ?: null;
    }

    /**
     * Lock unfinished, non-detached attempts of a quote in ascending id order.
     *
     * Each row carries lease_expired (1 or 0) computed with database time.
     *
     * @param AdapterInterface $connection
     * @param int $quoteId
     * @return array<int,array<string,mixed>>
     */
    public function lockOpenForQuote(AdapterInterface $connection, int $quoteId): array
    {
        return $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName(self::TABLE),
                    ['*', 'lease_expired' => new \Zend_Db_Expr('lease_until < NOW()')]
                )
                ->where('quote_id = ?', $quoteId)
                ->where('status IN (?)', [AttemptStatus::STARTED, AttemptStatus::PAYING])
                ->where('detached_at IS NULL')
                ->order('attempt_id ASC')
                ->forUpdate(true)
        );
    }

    /**
     * Change the status of a locked attempt, validating the transition.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $attempt Locked row
     * @param string $to
     * @param array<string,mixed> $extra Additional columns
     * @return void
     */
    public function transition(AdapterInterface $connection, array $attempt, string $to, array $extra = []): void
    {
        $this->transitions->assert(StateTransitionValidator::ATTEMPT, (string)$attempt['status'], $to);
        $data = ['status' => $to] + $extra;
        if (in_array($to, [AttemptStatus::COMPLETED, AttemptStatus::FAILED], true)) {
            $data['completed_at'] = new \Zend_Db_Expr('NOW()');
        }
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            $data,
            ['attempt_id = ?' => (int)$attempt['attempt_id']]
        );
    }

    /**
     * Mark a locked paying attempt as detached (hold kept, quote items released for a retry).
     *
     * @param AdapterInterface $connection
     * @param int $attemptId
     * @return void
     */
    public function markDetached(AdapterInterface $connection, int $attemptId): void
    {
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            ['detached_at' => new \Zend_Db_Expr('NOW()')],
            ['attempt_id = ?' => $attemptId]
        );
    }
}
