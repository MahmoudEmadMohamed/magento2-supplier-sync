<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api;

/**
 * Accept a supplier stock and cost snapshot. Validation is synchronous; applying it is asynchronous.
 *
 * Decimals are strings so they reach the hash and the database without floating-point rounding.
 *
 * @api
 */
interface StockUpdateManagementInterface
{
    /**
     * Accept a stock update, or return the stored receipt for an identical replay.
     *
     * @param string $supplierCode
     * @param string $idempotencyKey 1-128 characters of [A-Za-z0-9._:-], unique per supplier
     * @param string $sku
     * @param string $quantity Absolute snapshot, >= 0, at most 4 decimal places
     * @param string $unitPrice Supplier purchase cost, >= 0, at most 6 decimal places
     * @param string $currency Website base currency (ISO 4217)
     * @param string $websiteCode
     * @return \Company\SupplierSync\Api\Data\UpdateReceiptInterface
     * @throws \Magento\Framework\Webapi\Exception with an error_code parameter
     */
    public function submit(
        string $supplierCode,
        string $idempotencyKey,
        string $sku,
        string $quantity,
        string $unitPrice,
        string $currency,
        string $websiteCode
    ): Data\UpdateReceiptInterface;
}
