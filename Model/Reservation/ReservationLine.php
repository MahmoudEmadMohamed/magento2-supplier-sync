<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Reservation;

/**
 * One supplier-backed order line to reserve: the simple product's quote item, SKU and quantity.
 */
class ReservationLine
{
    /**
     * @param int $quoteItemId Quote item of the simple product (the child for configurables)
     * @param string $sku
     * @param int $websiteId
     * @param int $supplierId Assigned supplier at reservation time
     * @param string $qty Absolute quantity (from the parent line for configurables)
     */
    public function __construct(
        public readonly int $quoteItemId,
        public readonly string $sku,
        public readonly int $websiteId,
        public readonly int $supplierId,
        public readonly string $qty
    ) {
    }
}
