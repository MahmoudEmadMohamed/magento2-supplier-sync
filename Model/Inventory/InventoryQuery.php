<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Inventory;

use Company\SupplierSync\Api\AssignmentManagementInterface;
use Company\SupplierSync\Api\InventoryQueryInterface;
use Company\SupplierSync\Model\Data\InventoryView;
use Company\SupplierSync\Model\WebsiteResolver;
use Magento\Framework\App\ResourceConnection;

/**
 * Inventory counters with derived availability and shortage.
 */
class InventoryQuery implements InventoryQueryInterface
{
    /**
     * @param ResourceConnection $resource
     * @param WebsiteResolver $websiteResolver
     * @param AssignmentManagementInterface $assignments
     * @param InventoryLedger $inventoryLedger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly WebsiteResolver $websiteResolver,
        private readonly AssignmentManagementInterface $assignments,
        private readonly InventoryLedger $inventoryLedger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function get(string $sku, string $websiteCode): array
    {
        $sku = trim($sku);
        $websiteId = $this->websiteResolver->getIdByCode($websiteCode);
        $assignedSupplierId = $this->assignments->getAssignedSupplierId($sku, $websiteId);
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['i' => $this->resource->getTableName(InventoryLedger::TABLE)])
                ->join(
                    ['s' => $this->resource->getTableName('company_supplier')],
                    's.supplier_id = i.supplier_id',
                    ['supplier_code' => 'code']
                )
                ->where('i.sku = ?', $sku)
                ->where('i.website_id = ?', $websiteId)
                ->order('i.inventory_id')
        );
        $views = [];
        foreach ($rows as $row) {
            $row['website_code'] = trim($websiteCode);
            $row['is_assigned'] = (int)$row['supplier_id'] === $assignedSupplierId;
            $row['available'] = $this->inventoryLedger->getAvailable($row);
            $row['shortage'] = $this->inventoryLedger->getShortage($row);
            $views[] = new InventoryView($row);
        }
        return $views;
    }
}
