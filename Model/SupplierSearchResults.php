<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

use Company\SupplierSync\Api\Data\SupplierSearchResultsInterface;
use Magento\Framework\Api\SearchResults;

class SupplierSearchResults extends SearchResults implements SupplierSearchResultsInterface
{
}
