<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Placement;

use Company\SupplierSync\Api\AssignmentManagementInterface;
use Company\SupplierSync\Model\Decimal;
use Company\SupplierSync\Model\Reservation\ReservationLine;
use Magento\Catalog\Model\Product\Type;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Finds the supplier-backed lines of an order that is about to be placed.
 *
 * A line is supplier-backed when it is a simple product (on its own or as a configurable child) with a supplier
 * assignment for the order's website. Configurable children take the SKU and quote item from the child and the
 * quantity from the parent: the child's quote quantity is relative to its parent.
 */
class LineResolver
{
    /**
     * @param AssignmentManagementInterface $assignments
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly AssignmentManagementInterface $assignments,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Resolve supplier-backed lines; an empty result means the order keeps normal Magento behaviour.
     *
     * @param OrderInterface $order
     * @return ReservationLine[]
     */
    public function resolve(OrderInterface $order): array
    {
        $websiteId = (int)$this->storeManager->getStore((int)$order->getStoreId())->getWebsiteId();
        $lines = [];
        foreach ($order->getItems() ?? [] as $item) {
            if ($item->getProductType() !== Type::TYPE_SIMPLE || !$item->getQuoteItemId()) {
                continue;
            }
            $parent = $item->getParentItem();
            if ($parent !== null && $parent->getProductType() !== 'configurable') {
                continue; // Bundle children are not supported (documented).
            }
            $supplierId = $this->assignments->getAssignedSupplierId((string)$item->getSku(), $websiteId);
            if ($supplierId === null) {
                continue;
            }
            $lines[] = new ReservationLine(
                (int)$item->getQuoteItemId(),
                (string)$item->getSku(),
                $websiteId,
                $supplierId,
                Decimal::fromNumber(($parent ?? $item)->getQtyOrdered())
            );
        }
        return $lines;
    }
}
