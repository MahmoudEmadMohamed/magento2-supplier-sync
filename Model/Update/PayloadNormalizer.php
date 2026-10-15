<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Update;

use Company\SupplierSync\Exception\ApiException;
use Company\SupplierSync\Model\Decimal;

/**
 * Syntactic validation and canonical form of a stock update. Needs no database: it runs before the replay check.
 */
class PayloadNormalizer
{
    private const KEY_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';
    private const QTY_INTEGER_DIGITS = 8;   // DECIMAL(12,4)
    private const PRICE_INTEGER_DIGITS = 14; // DECIMAL(20,6)

    /**
     * Validate and normalize. Equivalent payloads (49.99 vs 49.990, usd vs USD) get the same hash.
     *
     * @param string $supplierCode
     * @param string $idempotencyKey
     * @param string $sku
     * @param string $quantity
     * @param string $unitPrice
     * @param string $currency
     * @param string $websiteCode
     * @return NormalizedPayload
     * @throws ApiException 400 for malformed fields
     */
    public function normalize(
        string $supplierCode,
        string $idempotencyKey,
        string $sku,
        string $quantity,
        string $unitPrice,
        string $currency,
        string $websiteCode
    ): NormalizedPayload {
        $key = trim($idempotencyKey);
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new ApiException(
                ApiException::INVALID_IDEMPOTENCY_KEY,
                'The idempotency key must be 1-128 characters of letters, digits, ".", "_", ":" or "-".'
            );
        }
        $sku = trim($sku);
        if ($sku === '' || mb_strlen($sku) > 64) {
            throw new ApiException(ApiException::INVALID_SKU, 'The SKU must be 1-64 characters.');
        }
        try {
            $qty = Decimal::normalize(trim($quantity), Decimal::QTY_SCALE, self::QTY_INTEGER_DIGITS);
        } catch (\InvalidArgumentException) {
            throw new ApiException(
                ApiException::INVALID_QTY,
                'The quantity must be a non-negative decimal below 100000000 with at most %scale decimal places.',
                ['scale' => Decimal::QTY_SCALE]
            );
        }
        try {
            $price = Decimal::normalize(trim($unitPrice), Decimal::PRICE_SCALE, self::PRICE_INTEGER_DIGITS);
        } catch (\InvalidArgumentException) {
            throw new ApiException(
                ApiException::INVALID_PRICE,
                'The unit price must be a non-negative decimal with at most %scale decimal places.',
                ['scale' => Decimal::PRICE_SCALE]
            );
        }
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ApiException(ApiException::INVALID_CURRENCY, 'The currency must be a 3-letter ISO 4217 code.');
        }
        $supplierCode = trim($supplierCode);
        $websiteCode = trim($websiteCode);
        $canonical = [
            'currency' => $currency,
            'quantity' => $qty,
            'sku' => $sku,
            'supplier_code' => $supplierCode,
            'unit_price' => $price,
            'website_code' => $websiteCode,
        ];
        ksort($canonical);
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return new NormalizedPayload($supplierCode, $key, $sku, $qty, $price, $currency, $websiteCode, $hash);
    }
}
