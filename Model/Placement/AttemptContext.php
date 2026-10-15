<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Placement;

/**
 * Binds a placement attempt to the exact order object being placed.
 *
 * The plugin stamps the attempt id on the order object it passes to proceed(); both fences read it from the same
 * object (verified on every supported path in the P2 spike). It is object data, never a column, so it is not saved.
 */
class AttemptContext
{
    public const ORDER_KEY = 'supplier_sync_attempt_id';
}
