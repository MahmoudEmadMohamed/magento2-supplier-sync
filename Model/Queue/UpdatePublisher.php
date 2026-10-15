<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes update ids from the outbox. Publication is at-least-once; the consumer's exclusive claim absorbs
 * duplicates.
 */
class UpdatePublisher
{
    public const TOPIC = 'supplier_sync.update.apply';
    private const OUTBOX = 'company_supplier_outbox';
    private const CLAIM_SECONDS = 60;

    /**
     * @param ResourceConnection $resource
     * @param PublisherInterface $publisher
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Best-effort publish of a just-accepted update, right after its transaction committed.
     *
     * Failures are logged and swallowed: the outbox row stays unpublished and the dispatcher cron sends it.
     *
     * @param int $updateId
     * @return void
     */
    public function publishAccepted(int $updateId): void
    {
        $connection = $this->resource->getConnection();
        $token = bin2hex(random_bytes(16));
        $claimed = $connection->update(
            $this->resource->getTableName(self::OUTBOX),
            [
                'claim_token' => $token,
                'claimed_until' => new \Zend_Db_Expr(sprintf('NOW() + INTERVAL %d SECOND', self::CLAIM_SECONDS)),
            ],
            [
                'update_id = ?' => $updateId,
                'published_at IS NULL',
                '(next_publish_at IS NULL OR next_publish_at <= NOW())',
                '(claimed_until IS NULL OR claimed_until < NOW())',
            ]
        );
        if ($claimed === 1) {
            $this->publishClaimed($updateId, $token);
        }
    }

    /**
     * Publish an outbox row this process has claimed, then record the publication if the claim still holds.
     *
     * @param int $updateId
     * @param string $token
     * @return bool Whether the message was handed to the broker
     */
    public function publishClaimed(int $updateId, string $token): bool
    {
        try {
            $this->publisher->publish(self::TOPIC, $updateId);
        } catch (\Throwable $e) {
            $this->logger->warning('Outbox publish failed; the dispatcher will retry.', [
                'update_id' => $updateId,
                'exception' => get_class($e),
            ]);
            return false;
        }
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::OUTBOX),
            [
                'published_at' => new \Zend_Db_Expr('NOW()'),
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
                'claim_token' => null,
                'claimed_until' => null,
            ],
            ['update_id = ?' => $updateId, 'claim_token = ?' => $token]
        );
        return true;
    }
}
