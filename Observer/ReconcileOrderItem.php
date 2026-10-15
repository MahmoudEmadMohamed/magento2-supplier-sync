<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Observer;

use Company\SupplierSync\Model\Reservation\ReconcileService;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Psr\Log\LoggerInterface;

/**
 * sales_order_item_save_commit_after: reconcile the item's order with its persisted quantities.
 *
 * The order-level commit event is not fired when only item rows change (e.g. a second partial shipment), so this
 * item-level event is the trigger (see docs/architecture.md, spike findings). It fires after commit, so the
 * quantities it reads are persisted. Core swallows exceptions from commit callbacks; failures are logged here and
 * the reconciliation cron catches up.
 */
class ReconcileOrderItem implements ObserverInterface
{
    /**
     * @param ReconcileService $reconcileService
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ReconcileService $reconcileService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Reconcile the order the saved item belongs to.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getData('item');
        $orderId = $item instanceof OrderItemInterface ? (int)$item->getOrderId() : 0;
        if (!$orderId) {
            return;
        }
        try {
            $this->reconcileService->reconcileOrder($orderId);
        } catch (\Throwable $e) {
            $this->logger->error('Order reconcile failed; the reconciliation cron will retry.', [
                'order_id' => $orderId,
                'exception' => get_class($e),
            ]);
        }
    }
}
