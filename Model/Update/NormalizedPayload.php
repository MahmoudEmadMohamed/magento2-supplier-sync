<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Update;

/**
 * A syntactically valid, normalized stock update and the hash that identifies it.
 */
class NormalizedPayload
{
    /**
     * @param string $supplierCode
     * @param string $idempotencyKey
     * @param string $sku
     * @param string $quantity Scale 4
     * @param string $unitPrice Scale 6
     * @param string $currency Upper case
     * @param string $websiteCode
     * @param string $hash SHA-256 of the canonical JSON of the fields above except the key
     */
    public function __construct(
        public readonly string $supplierCode,
        public readonly string $idempotencyKey,
        public readonly string $sku,
        public readonly string $quantity,
        public readonly string $unitPrice,
        public readonly string $currency,
        public readonly string $websiteCode,
        public readonly string $hash
    ) {
    }
}
