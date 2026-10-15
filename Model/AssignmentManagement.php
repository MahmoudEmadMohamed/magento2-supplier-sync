<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

use Company\SupplierSync\Api\AssignmentManagementInterface;
use Company\SupplierSync\Api\SupplierRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Type;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Supplier assignments per SKU and website.
 */
class AssignmentManagement implements AssignmentManagementInterface
{
    private const TABLE = 'company_supplier_assignment';

    /**
     * __construct.
     *
     * @param ResourceConnection $resource
     * @param SupplierRepositoryInterface $supplierRepository
     * @param WebsiteResolver $websiteResolver
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SupplierRepositoryInterface $supplierRepository,
        private readonly WebsiteResolver $websiteResolver,
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function assign(string $sku, string $websiteCode, string $supplierCode): void
    {
        $sku = trim($sku);
        $supplierId = (int)$this->supplierRepository->getByCode($supplierCode)->getSupplierId();
        $websiteId = $this->websiteResolver->getIdByCode($websiteCode);
        $product = $this->productRepository->get($sku);
        if ($product->getTypeId() !== Type::TYPE_SIMPLE) {
            throw new LocalizedException(
                __('Only simple products can be supplier-backed; "%1" is %2.', $sku, $product->getTypeId())
            );
        }
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            ['sku' => $sku, 'website_id' => $websiteId, 'supplier_id' => $supplierId],
            ['supplier_id']
        );
    }

    /**
     * @inheritDoc
     */
    public function unassign(string $sku, string $websiteCode): void
    {
        $websiteId = $this->websiteResolver->getIdByCode($websiteCode);
        $deleted = $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['sku = ?' => trim($sku), 'website_id = ?' => $websiteId]
        );
        if ($deleted === 0) {
            throw new NoSuchEntityException(__('"%1" has no supplier assignment on website "%2".', $sku, $websiteCode));
        }
    }

    /**
     * @inheritDoc
     */
    public function getAssignedSupplierId(string $sku, int $websiteId): ?int
    {
        $connection = $this->resource->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['supplier_id'])
                ->where('sku = ?', $sku)
                ->where('website_id = ?', $websiteId)
        );
        return $id === false ? null : (int)$id;
    }

    /**
     * @inheritDoc
     */
    public function getList(?string $sku = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName(self::TABLE)], ['sku', 'website_id', 'supplier_id'])
            ->join(
                ['s' => $this->resource->getTableName('company_supplier')],
                's.supplier_id = a.supplier_id',
                ['supplier_code' => 'code']
            )
            ->order(['a.sku', 'a.website_id']);
        if ($sku !== null) {
            $select->where('a.sku = ?', $sku);
        }
        return $connection->fetchAll($select);
    }
}
