<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

/**
 * Stock update statuses.
 */
class UpdateStatus
{
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const RETRY_PENDING = 'retry_pending';
    public const APPLIED = 'applied';
    public const SKIPPED_STALE = 'skipped_stale';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';

    /** Statuses after which a delivery has nothing left to do. */
    public const TERMINAL = [self::APPLIED, self::SKIPPED_STALE, self::REJECTED, self::FAILED];
}
