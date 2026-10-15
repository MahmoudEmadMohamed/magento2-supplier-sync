<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api;

/**
 * Enables or disables a supplier for one website.
 *
 * A supplier serves a website only when it has an active row for that website.
 *
 * @api
 */
interface SupplierWebsiteManagementInterface
{
    /**
     * Set active.
     *
     * @param string $supplierCode
     * @param string $websiteCode
     * @param bool $isActive
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function setActive(string $supplierCode, string $websiteCode, bool $isActive): void;

    /**
     * Website rows for a supplier: [website_id => is_active].
     *
     * @param int $supplierId
     * @return array<int, bool>
     */
    public function getWebsites(int $supplierId): array;
}
