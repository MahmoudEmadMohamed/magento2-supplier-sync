<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Placement failed because a supplier-backed line could not be reserved. The message is customer-safe.
 */
class InsufficientSupplierStockException extends LocalizedException
{
}
