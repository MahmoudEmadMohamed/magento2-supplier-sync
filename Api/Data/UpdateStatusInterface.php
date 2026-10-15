<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api\Data;

/**
 * Processing status of an accepted stock update.
 *
 * @api
 */
interface UpdateStatusInterface
{
    /**
     * Get update id.
     *
     * @return int
     */
    public function getUpdateId(): int;

    /**
     * Get supplier code.
     *
     * @return string
     */
    public function getSupplierCode(): string;

    /**
     * Get idempotency key.
     *
     * @return string
     */
    public function getIdempotencyKey(): string;

    /**
     * Get SKU.
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * Get website code.
     *
     * @return string
     */
    public function getWebsiteCode(): string;

    /**
     * Get the normalized snapshot quantity.
     *
     * @return string
     */
    public function getQuantity(): string;

    /**
     * Get the normalized supplier unit cost.
     *
     * @return string
     */
    public function getUnitPrice(): string;

    /**
     * Get currency.
     *
     * @return string
     */
    public function getCurrency(): string;

    /**
     * Get status.
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * Number of processing claims so far.
     *
     * @return int
     */
    public function getAttempts(): int;

    /**
     * Accepted at (database time).
     *
     * @return string
     */
    public function getAcceptedAt(): string;

    /**
     * When the next retry is due, for retry_pending updates.
     *
     * @return string|null
     */
    public function getNextAttemptAt(): ?string;

    /**
     * When a terminal status was reached.
     *
     * @return string|null
     */
    public function getProcessedAt(): ?string;

    /**
     * Machine-readable error code, if any.
     *
     * @return string|null
     */
    public function getErrorCode(): ?string;

    /**
     * Safe, human-readable error, if any.
     *
     * @return string|null
     */
    public function getError(): ?string;
}
