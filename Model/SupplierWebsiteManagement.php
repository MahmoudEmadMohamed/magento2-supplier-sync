<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

use Company\SupplierSync\Api\SupplierRepositoryInterface;
use Company\SupplierSync\Api\SupplierWebsiteManagementInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Supplier website enablement.
 */
class SupplierWebsiteManagement implements SupplierWebsiteManagementInterface
{
    private const TABLE = 'company_supplier_website';

    /**
     * __construct.
     *
     * @param ResourceConnection $resource
     * @param SupplierRepositoryInterface $supplierRepository
     * @param WebsiteResolver $websiteResolver
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SupplierRepositoryInterface $supplierRepository,
        private readonly WebsiteResolver $websiteResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function setActive(string $supplierCode, string $websiteCode, bool $isActive): void
    {
        $supplierId = (int)$this->supplierRepository->getByCode($supplierCode)->getSupplierId();
        $websiteId = $this->websiteResolver->getIdByCode($websiteCode);
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            ['supplier_id' => $supplierId, 'website_id' => $websiteId, 'is_active' => (int)$isActive],
            ['is_active']
        );
    }

    /**
     * @inheritDoc
     */
    public function getWebsites(int $supplierId): array
    {
        $connection = $this->resource->getConnection();
        $pairs = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['website_id', 'is_active'])
                ->where('supplier_id = ?', $supplierId)
        );
        return array_map('boolval', $pairs);
    }
}
