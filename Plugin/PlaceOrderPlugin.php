<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Plugin;

use Company\SupplierSync\Model\Config;
use Company\SupplierSync\Model\Placement\AttemptContext;
use Company\SupplierSync\Model\Placement\AttemptResolver;
use Company\SupplierSync\Model\Placement\LineResolver;
use Company\SupplierSync\Model\Placement\PlacementService;
use Company\SupplierSync\Model\Reservation\ReconcileService;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Psr\Log\LoggerInterface;

/**
 * Takes supplier holds before payment and settles them after placement.
 *
 * 1. Resolve supplier-backed lines; without any, call proceed() untouched.
 * 2. Refuse to run inside an open transaction.
 * 3. Create an attempt and reserve (committed) - insufficient stock fails here, before payment.
 * 4. Stamp the attempt on the order object, then proceed(): payment and order save run there, guarded by the
 *    pre-payment and pre-commit fences.
 * 5. Success: link holds to order items and reconcile. Exception: link if the order committed, else release.
 */
class PlaceOrderPlugin
{
    /**
     * @param LineResolver $lineResolver
     * @param PlacementService $placementService
     * @param AttemptResolver $attemptResolver
     * @param ReconcileService $reconcileService
     * @param ResourceConnection $resource
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LineResolver $lineResolver,
        private readonly PlacementService $placementService,
        private readonly AttemptResolver $attemptResolver,
        private readonly ReconcileService $reconcileService,
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Wrap order placement with supplier holds.
     *
     * @param OrderManagementInterface $subject
     * @param callable $proceed
     * @param OrderInterface $order
     * @return OrderInterface
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundPlace(OrderManagementInterface $subject, callable $proceed, OrderInterface $order)
    {
        $lines = $this->lineResolver->resolve($order);
        if (!$lines) {
            return $proceed($order);
        }
        if ($this->config->isAsyncOrderPlacementEnabled()) {
            $this->logger->warning('Asynchronous order placement is enabled; it is not supported for supplier stock.');
        }
        if ($this->resource->getConnection()->getTransactionLevel() > 0) {
            $this->logger->error('Supplier-backed order placed inside an open transaction; refused.', [
                'quote_id' => (int)$order->getQuoteId(),
            ]);
            throw new LocalizedException(
                __('Supplier-backed orders cannot be placed inside an existing transaction.')
            );
        }

        if (!$order instanceof DataObject) {
            // The attempt id is carried on the order object to the fences; without it they could not act.
            throw new LocalizedException(__('Supplier-backed orders cannot be placed with this order implementation.'));
        }
        $attemptId = $this->placementService->begin(
            (int)$order->getQuoteId(),
            $order->getIncrementId() ? (string)$order->getIncrementId() : null,
            $lines
        );
        $order->setData(AttemptContext::ORDER_KEY, $attemptId);

        try {
            $result = $proceed($order);
        } catch (\Throwable $e) {
            $this->settleAfterException($attemptId);
            throw $e;
        }
        try {
            $orderId = $this->attemptResolver->link($attemptId);
            if ($orderId !== null) {
                $this->reconcileService->reconcileOrder($orderId);
            }
        } catch (\Throwable $e) {
            // The order is committed and must not fail now; the reconciliation cron links it.
            $this->logger->error('Linking supplier holds failed; the reconciliation cron will retry.', [
                'attempt_id' => $attemptId,
                'exception' => get_class($e),
            ]);
        }
        return $result;
    }

    /**
     * Link or release after proceed() threw, without masking the original exception.
     *
     * @param int $attemptId
     * @return void
     */
    private function settleAfterException(int $attemptId): void
    {
        try {
            $orderId = $this->attemptResolver->resolveAfterException($attemptId);
            if ($orderId !== null) {
                $this->reconcileService->reconcileOrder($orderId);
            }
        } catch (\Throwable $e) {
            // Recovery by attempt status (reconciliation cron) settles it when the lease expires.
            $this->logger->error('Settling a failed placement attempt failed; recovery will settle it.', [
                'attempt_id' => $attemptId,
                'exception' => get_class($e),
            ]);
        }
    }
}
