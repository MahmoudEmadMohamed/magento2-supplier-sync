<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\ResourceModel\Supplier;

use Company\SupplierSync\Model\ResourceModel\Supplier as SupplierResource;
use Company\SupplierSync\Model\Supplier;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Supplier collection.
 */
class Collection extends AbstractCollection
{
    /**
     * _construct.
     *
     * @var string
     */
    protected $_idFieldName = 'supplier_id';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(Supplier::class, SupplierResource::class);
    }
}
