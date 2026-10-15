<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Update;

use Company\SupplierSync\Model\StateTransitionValidator;
use Company\SupplierSync\Model\UpdateStatus;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Row access to stock updates, their outbox rows and their audit trail. Callers own the transaction.
 */
class UpdateStore
{
    public const TABLE = 'company_supplier_update';
    public const OUTBOX_TABLE = 'company_supplier_outbox';
    public const EVENT_TABLE = 'company_supplier_update_event';

    /**
     * @param ResourceConnection $resource
     * @param StateTransitionValidator $transitions
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StateTransitionValidator $transitions
    ) {
    }

    /**
     * Find an update by supplier and idempotency key (plain read).
     *
     * @param AdapterInterface $connection
     * @param int $supplierId
     * @param string $idempotencyKey
     * @return array<string,mixed>|null
     */
    public function findByKey(AdapterInterface $connection, int $supplierId, string $idempotencyKey): ?array
    {
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('supplier_id = ?', $supplierId)
                ->where('idempotency_key = ?', $idempotencyKey)
        );
        return $row ?: null;
    }

    /**
     * Insert an accepted update with its outbox row and first audit event. Returns the update id.
     *
     * The fulfilment watermark is the highest fulfil event id of the target inventory row, read without a lock:
     * a fulfil that commits later has a higher id (fulfils are inserted under the inventory row lock), so it is
     * treated as not reflected by this snapshot. That errs toward under-selling.
     *
     * @param AdapterInterface $connection
     * @param int $supplierId
     * @param int $websiteId
     * @param NormalizedPayload $payload
     * @return int
     * @throws \Magento\Framework\DB\Adapter\DuplicateException when the key was accepted concurrently
     */
    public function insertAccepted(
        AdapterInterface $connection,
        int $supplierId,
        int $websiteId,
        NormalizedPayload $payload
    ): int {
        $watermark = (int)$connection->fetchOne(
            $connection->select()
                ->from(['e' => $this->resource->getTableName('company_supplier_reservation_event')], [])
                ->join(
                    ['i' => $this->resource->getTableName('company_supplier_inventory')],
                    'i.inventory_id = e.inventory_id',
                    []
                )
                ->columns(['watermark' => new \Zend_Db_Expr('COALESCE(MAX(e.event_id), 0)')])
                ->where('i.supplier_id = ?', $supplierId)
                ->where('i.sku = ?', $payload->sku)
                ->where('i.website_id = ?', $websiteId)
                ->where('e.type = ?', 'fulfil')
        );
        $table = $this->resource->getTableName(self::TABLE);
        $connection->insert($table, [
            'supplier_id' => $supplierId,
            'idempotency_key' => $payload->idempotencyKey,
            'payload_hash' => $payload->hash,
            'sku' => $payload->sku,
            'website_id' => $websiteId,
            'quantity' => $payload->quantity,
            'unit_cost' => $payload->unitPrice,
            'currency' => $payload->currency,
            'fulfil_watermark' => $watermark,
            'accepted_at' => new \Zend_Db_Expr('NOW()'),
            'status' => UpdateStatus::QUEUED,
        ]);
        $updateId = (int)$connection->lastInsertId($table);
        $connection->insert($this->resource->getTableName(self::OUTBOX_TABLE), ['update_id' => $updateId]);
        $this->appendEvent($connection, $updateId, null, UpdateStatus::QUEUED, 0, 'accepted');
        return $updateId;
    }

    /**
     * Lock and return an update row with lease_expired and retry_due flags (database time), or null.
     *
     * @param AdapterInterface $connection
     * @param int $updateId
     * @return array<string,mixed>|null
     */
    public function lock(AdapterInterface $connection, int $updateId): ?array
    {
        $row = $connection->fetchRow($this->selectWithFlags($connection, $updateId)->forUpdate(true));
        return $row ?: null;
    }

    /**
     * Lock an update row unless another transaction holds it; return null in that case (or if it is missing).
     *
     * Used by the sweep: a row locked by a worker's apply transaction is skipped, never waited on.
     *
     * @param AdapterInterface $connection
     * @param int $updateId
     * @return array<string,mixed>|null
     */
    public function lockSkipLocked(AdapterInterface $connection, int $updateId): ?array
    {
        $row = $connection->fetchRow(
            $this->selectWithFlags($connection, $updateId)->assemble() . ' FOR UPDATE SKIP LOCKED'
        );
        return $row ?: null;
    }

    /**
     * Select one update row with lease_expired and retry_due flags computed in database time.
     *
     * @param AdapterInterface $connection
     * @param int $updateId
     * @return \Magento\Framework\DB\Select
     */
    private function selectWithFlags(AdapterInterface $connection, int $updateId): \Magento\Framework\DB\Select
    {
        return $connection->select()
            ->from($this->resource->getTableName(self::TABLE), [
                '*',
                'lease_expired' => new \Zend_Db_Expr('(lease_until IS NOT NULL AND lease_until < NOW())'),
                'retry_due' => new \Zend_Db_Expr('(next_attempt_at IS NULL OR next_attempt_at <= NOW())'),
            ])
            ->where('update_id = ?', $updateId);
    }

    /**
     * Change the status of a locked update, validating the transition and writing an audit event.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $update Locked row
     * @param string $to
     * @param array<string,mixed> $extra Additional columns
     * @param string|null $message Safe message for the audit trail
     * @return void
     */
    public function transition(
        AdapterInterface $connection,
        array $update,
        string $to,
        array $extra = [],
        ?string $message = null
    ): void {
        $from = (string)$update['status'];
        $this->transitions->assert(StateTransitionValidator::UPDATE, $from, $to);
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            ['status' => $to] + $extra,
            ['update_id = ?' => (int)$update['update_id']]
        );
        $attempts = (int)($extra['attempts'] ?? $update['attempts']);
        $this->appendEvent($connection, (int)$update['update_id'], $from, $to, $attempts, $message);
    }

    /**
     * Append an audit event.
     *
     * @param AdapterInterface $connection
     * @param int $updateId
     * @param string|null $from
     * @param string $to
     * @param int $attempt
     * @param string|null $message
     * @return void
     */
    public function appendEvent(
        AdapterInterface $connection,
        int $updateId,
        ?string $from,
        string $to,
        int $attempt,
        ?string $message
    ): void {
        $connection->insert($this->resource->getTableName(self::EVENT_TABLE), [
            'update_id' => $updateId,
            'from_status' => $from,
            'to_status' => $to,
            'attempt' => $attempt,
            'safe_message' => $message === null ? null : mb_substr($message, 0, 255),
        ]);
    }

    /**
     * Make the outbox row publishable again, not before the given time (null: now).
     *
     * @param AdapterInterface $connection
     * @param int $updateId
     * @param \Zend_Db_Expr|null $notBefore SQL datetime expression; null publishes as soon as possible
     * @return void
     */
    public function rearmOutbox(AdapterInterface $connection, int $updateId, ?\Zend_Db_Expr $notBefore): void
    {
        $connection->update(
            $this->resource->getTableName(self::OUTBOX_TABLE),
            [
                'published_at' => null,
                'next_publish_at' => $notBefore,
                'claim_token' => null,
                'claimed_until' => null,
            ],
            ['update_id = ?' => $updateId]
        );
    }
}
