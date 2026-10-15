<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api;

/**
 * One supplier per SKU and website, through an explicit assignment.
 *
 * Reassignment never moves existing reservations: they stay on the original supplier's inventory row.
 *
 * @api
 */
interface AssignmentManagementInterface
{
    /**
     * Assign (or reassign) a SKU on a website to a supplier.
     *
     * @param string $sku
     * @param string $websiteCode
     * @param string $supplierCode
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assign(string $sku, string $websiteCode, string $supplierCode): void;

    /**
     * Unassign.
     *
     * @param string $sku
     * @param string $websiteCode
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function unassign(string $sku, string $websiteCode): void;

    /**
     * Get assigned supplier id.
     *
     * @param string $sku
     * @param int $websiteId
     * @return int|null
     */
    public function getAssignedSupplierId(string $sku, int $websiteId): ?int;

    /**
     * Rows of sku, website_id, supplier_id, supplier_code.
     *
     * @param string|null $sku
     * @return array<int,array<string,mixed>>
     */
    public function getList(?string $sku = null): array;
}
