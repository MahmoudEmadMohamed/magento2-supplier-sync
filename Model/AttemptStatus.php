<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

/**
 * Placement attempt statuses.
 */
class AttemptStatus
{
    /** Attempt created; payment has not begun. */
    public const STARTED = 'started';
    /** Passed the pre-payment fence; payment may be in flight. */
    public const PAYING = 'paying';
    /** Passed the pre-commit fence inside the order transaction; the order exists. */
    public const ORDER_SAVED = 'order_saved';
    /** Reservations linked to order items. */
    public const COMPLETED = 'completed';
    /** Holds released; both fences reject any later progress. */
    public const FAILED = 'failed';
}
