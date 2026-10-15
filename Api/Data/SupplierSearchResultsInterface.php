<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * @api
 */
interface SupplierSearchResultsInterface extends SearchResultsInterface
{
    /**
     * Get items.
     *
     * @return \Company\SupplierSync\Api\Data\SupplierInterface[]
     */
    public function getItems();

    /**
     * Set items.
     *
     * @param \Company\SupplierSync\Api\Data\SupplierInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
