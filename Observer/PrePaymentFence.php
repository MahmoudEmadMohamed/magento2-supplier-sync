<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Observer;

use Company\SupplierSync\Model\AttemptStatus;
use Company\SupplierSync\Model\Config;
use Company\SupplierSync\Model\Placement\AttemptContext;
use Company\SupplierSync\Model\Placement\AttemptStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * sales_order_place_before: started → paying, atomically, before any payment code runs.
 *
 * If the attempt is no longer started (released by recovery), payment never begins.
 */
class PrePaymentFence implements ObserverInterface
{
    /**
     * @param ResourceConnection $resource
     * @param Config $config
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config
    ) {
    }

    /**
     * Move the stamped attempt to paying or stop placement.
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        $attemptId = $order instanceof DataObject ? $order->getData(AttemptContext::ORDER_KEY) : null;
        if (!$attemptId) {
            return;
        }
        $updated = $this->resource->getConnection()->update(
            $this->resource->getTableName(AttemptStore::TABLE),
            [
                'status' => AttemptStatus::PAYING,
                'lease_until' => new \Zend_Db_Expr(
                    sprintf('NOW() + INTERVAL %d SECOND', $this->config->getPlacementLeaseSeconds())
                ),
            ],
            ['attempt_id = ?' => (int)$attemptId, 'status = ?' => AttemptStatus::STARTED]
        );
        if ($updated !== 1) {
            throw new LocalizedException(__('The order could not be placed. Please try again.'));
        }
    }
}
