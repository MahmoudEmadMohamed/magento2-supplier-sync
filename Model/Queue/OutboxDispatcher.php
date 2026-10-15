<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Queue;

use Company\SupplierSync\Model\Db\TransactionRunner;
use Company\SupplierSync\Model\Update\UpdateStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Publishes eligible outbox rows. Several dispatchers can run at once: rows are claimed with SKIP LOCKED in a
 * short transaction and published outside it.
 */
class OutboxDispatcher
{
    private const CLAIM_SECONDS = 60;

    /**
     * @param TransactionRunner $transactionRunner
     * @param ResourceConnection $resource
     * @param UpdatePublisher $publisher
     */
    public function __construct(
        private readonly TransactionRunner $transactionRunner,
        private readonly ResourceConnection $resource,
        private readonly UpdatePublisher $publisher
    ) {
    }

    /**
     * Claim and publish up to $limit due rows. Returns [published, failed].
     *
     * @param int $limit
     * @return array{0:int,1:int}
     */
    public function dispatch(int $limit = 100): array
    {
        $token = bin2hex(random_bytes(16));
        $updateIds = $this->transactionRunner->run(function (AdapterInterface $connection) use ($limit, $token): array {
            $table = $this->resource->getTableName(UpdateStore::OUTBOX_TABLE);
            $select = $connection->select()
                ->from($table, ['outbox_id', 'update_id'])
                ->where('published_at IS NULL')
                ->where('next_publish_at IS NULL OR next_publish_at <= NOW()')
                ->where('claimed_until IS NULL OR claimed_until < NOW()')
                ->order('outbox_id ASC')
                ->limit($limit);
            $rows = $connection->fetchPairs($select->assemble() . ' FOR UPDATE SKIP LOCKED');
            if ($rows) {
                $connection->update(
                    $table,
                    [
                        'claim_token' => $token,
                        'claimed_until' => new \Zend_Db_Expr(
                            sprintf('NOW() + INTERVAL %d SECOND', self::CLAIM_SECONDS)
                        ),
                    ],
                    ['outbox_id IN (?)' => array_keys($rows)]
                );
            }
            return array_values($rows);
        });
        $published = 0;
        foreach ($updateIds as $updateId) {
            $published += (int)$this->publisher->publishClaimed((int)$updateId, $token);
        }
        return [$published, count($updateIds) - $published];
    }
}
