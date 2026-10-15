<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Update;

use Company\SupplierSync\Api\AssignmentManagementInterface;
use Company\SupplierSync\Api\Data\SupplierInterface;
use Company\SupplierSync\Api\SupplierWebsiteManagementInterface;
use Company\SupplierSync\Exception\ApiException;
use Company\SupplierSync\Model\Decimal;
use Company\SupplierSync\Model\WebsiteResolver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Admission rules for a NEW idempotency key. Identical replays skip these on purpose: a receipt stays valid
 * even if the supplier was disabled or the SKU reassigned after acceptance.
 */
class AdmissionValidator
{
    /**
     * @param WebsiteResolver $websiteResolver
     * @param SupplierWebsiteManagementInterface $supplierWebsites
     * @param ProductRepositoryInterface $productRepository
     * @param StockRegistryInterface $stockRegistry
     * @param AssignmentManagementInterface $assignments
     */
    public function __construct(
        private readonly WebsiteResolver $websiteResolver,
        private readonly SupplierWebsiteManagementInterface $supplierWebsites,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly AssignmentManagementInterface $assignments
    ) {
    }

    /**
     * Validate a new update and return the website id.
     *
     * @param NormalizedPayload $payload
     * @param SupplierInterface $supplier
     * @return int
     * @throws ApiException
     */
    public function validate(NormalizedPayload $payload, SupplierInterface $supplier): int
    {
        if (!$supplier->getIsActive()) {
            throw new ApiException(ApiException::SUPPLIER_DISABLED, 'Supplier %supplier is disabled.', [
                'supplier' => $supplier->getCode(),
            ]);
        }
        try {
            $websiteId = $this->websiteResolver->getIdByCode($payload->websiteCode);
        } catch (NoSuchEntityException) {
            throw new ApiException(ApiException::UNKNOWN_WEBSITE, 'Website %website does not exist.', [
                'website' => $payload->websiteCode,
            ]);
        }
        $websites = $this->supplierWebsites->getWebsites((int)$supplier->getSupplierId());
        if (empty($websites[$websiteId])) {
            throw new ApiException(
                ApiException::WEBSITE_DISABLED,
                'Supplier %supplier is not enabled for website %website.',
                ['supplier' => $supplier->getCode(), 'website' => $payload->websiteCode]
            );
        }

        try {
            $product = $this->productRepository->get($payload->sku);
        } catch (NoSuchEntityException) {
            throw new ApiException(ApiException::UNKNOWN_SKU, 'Product %sku does not exist.', ['sku' => $payload->sku]);
        }
        if ($product->getTypeId() !== Type::TYPE_SIMPLE) {
            throw new ApiException(
                ApiException::UNSUPPORTED_PRODUCT_TYPE,
                'Product %sku is of type %type; only simple products can be supplier-backed.',
                ['sku' => $payload->sku, 'type' => (string)$product->getTypeId()]
            );
        }
        $productWebsiteIds = $product instanceof Product ? (array)$product->getWebsiteIds() : [];
        if (!in_array($websiteId, array_map('intval', $productWebsiteIds), true)) {
            throw new ApiException(
                ApiException::PRODUCT_NOT_IN_WEBSITE,
                'Product %sku is not assigned to website %website.',
                ['sku' => $payload->sku, 'website' => $payload->websiteCode]
            );
        }
        if (Decimal::hasFraction($payload->quantity)
            && !$this->stockRegistry->getStockItemBySku($payload->sku)->getIsQtyDecimal()
        ) {
            throw new ApiException(
                ApiException::INVALID_QTY,
                'Product %sku does not allow decimal quantities.',
                ['sku' => $payload->sku]
            );
        }

        $assignedSupplierId = $this->assignments->getAssignedSupplierId($payload->sku, $websiteId);
        if ($assignedSupplierId === null) {
            throw new ApiException(
                ApiException::UNASSIGNED_SKU,
                'Product %sku has no supplier assignment on website %website.',
                ['sku' => $payload->sku, 'website' => $payload->websiteCode]
            );
        }
        if ($assignedSupplierId !== (int)$supplier->getSupplierId()) {
            throw new ApiException(
                ApiException::ASSIGNMENT_MISMATCH,
                'Product %sku on website %website is assigned to another supplier.',
                ['sku' => $payload->sku, 'website' => $payload->websiteCode],
                409
            );
        }

        $baseCurrency = $this->websiteResolver->getBaseCurrency($websiteId);
        if ($payload->currency !== $baseCurrency) {
            throw new ApiException(
                ApiException::CURRENCY_MISMATCH,
                'Currency %currency does not match the website base currency %base.',
                ['currency' => $payload->currency, 'base' => $baseCurrency]
            );
        }
        return $websiteId;
    }
}
