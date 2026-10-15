<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api;

/**
 * Read supplier inventory counters for a SKU on a website.
 *
 * @api
 */
interface InventoryQueryInterface
{
    /**
     * Get every supplier ledger row for the SKU and website (more than one after a reassignment).
     *
     * @param string $sku
     * @param string $websiteCode
     * @return \Company\SupplierSync\Api\Data\InventoryViewInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException for an unknown website
     */
    public function get(string $sku, string $websiteCode): array;
}
