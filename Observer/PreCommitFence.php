<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Observer;

use Company\SupplierSync\Model\AttemptStatus;
use Company\SupplierSync\Model\Placement\AttemptContext;
use Company\SupplierSync\Model\Placement\AttemptStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use LogicException;

/**
 * sales_order_save_after, inside the order transaction: paying → order_saved, under the attempt row lock.
 *
 * Accepting only paying means an order cannot commit for an attempt that was released (failed) or that skipped
 * the pre-payment fence (started). Throwing here rolls the whole order back.
 */
class PreCommitFence implements ObserverInterface
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Commit the attempt together with the order, or abort the order.
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        $attemptId = $order instanceof Order ? $order->getData(AttemptContext::ORDER_KEY) : null;
        if (!$attemptId) {
            return;
        }
        $connection = $this->resource->getConnection();
        // I1: the attempt update must join the order transaction.
        if ($order->getResource()->getConnection() !== $connection) {
            throw new LogicException('Order and supplier attempt do not share a database connection.');
        }
        $table = $this->resource->getTableName(AttemptStore::TABLE);
        $attempt = $connection->fetchRow(
            $connection->select()
                ->from($table, ['status', 'order_id'])
                ->where('attempt_id = ?', (int)$attemptId)
                ->forUpdate(true)
        );
        $orderId = (int)$order->getEntityId();
        $alreadyBound = $attempt
            && in_array($attempt['status'], [AttemptStatus::ORDER_SAVED, AttemptStatus::COMPLETED], true)
            && (int)$attempt['order_id'] === $orderId;
        if ($alreadyBound) {
            return; // The same order saved again in this request.
        }
        if (!$attempt || $attempt['status'] !== AttemptStatus::PAYING) {
            throw new LocalizedException(__('The order could not be placed. Please try again.'));
        }
        $connection->update(
            $table,
            ['status' => AttemptStatus::ORDER_SAVED, 'order_id' => $orderId],
            ['attempt_id = ?' => (int)$attemptId]
        );
    }
}
