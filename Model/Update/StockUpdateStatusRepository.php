<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Update;

use Company\SupplierSync\Api\Data\UpdateStatusInterface;
use Company\SupplierSync\Api\StockUpdateStatusRepositoryInterface;
use Company\SupplierSync\Model\Data\UpdateStatusView;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Reads update status for REST and CLI.
 */
class StockUpdateStatusRepository implements StockUpdateStatusRepositoryInterface
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @inheritDoc
     */
    public function get(int $updateId): UpdateStatusInterface
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(['u' => $this->resource->getTableName(UpdateStore::TABLE)])
                ->join(
                    ['s' => $this->resource->getTableName('company_supplier')],
                    's.supplier_id = u.supplier_id',
                    ['supplier_code' => 'code']
                )
                ->join(
                    ['w' => $this->resource->getTableName('store_website')],
                    'w.website_id = u.website_id',
                    ['website_code' => 'code']
                )
                ->where('u.update_id = ?', $updateId)
        );
        if (!$row) {
            throw new NoSuchEntityException(__('Stock update %1 does not exist.', $updateId));
        }
        return new UpdateStatusView($row);
    }
}
