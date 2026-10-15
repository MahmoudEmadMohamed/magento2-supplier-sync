<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api\Data;

/**
 * Receipt for an accepted (or replayed) stock update.
 *
 * @api
 */
interface UpdateReceiptInterface
{
    /**
     * Server acceptance id; updates are applied in this order.
     *
     * @return int
     */
    public function getUpdateId(): int;

    /**
     * Current status: queued, processing, retry_pending, applied, skipped_stale, rejected or failed.
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * True when this request repeated an already accepted idempotency key with the same payload.
     *
     * @return bool
     */
    public function getReplayed(): bool;
}
