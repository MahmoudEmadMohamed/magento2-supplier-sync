<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api\Data;

/**
 * Supplier inventory counters for one supplier, SKU and website.
 *
 * @api
 */
interface InventoryViewInterface
{
    /**
     * Get supplier code.
     *
     * @return string
     */
    public function getSupplierCode(): string;

    /**
     * Whether this supplier is the current assignment (older rows remain after a reassignment).
     *
     * @return bool
     */
    public function getIsAssigned(): bool;

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
     * Last applied snapshot.
     *
     * @return string
     */
    public function getQuantity(): string;

    /**
     * Open, unshipped reservations.
     *
     * @return string
     */
    public function getReservedQty(): string;

    /**
     * Shipped, not yet reflected in a snapshot.
     *
     * @return string
     */
    public function getFulfilledPendingQty(): string;

    /**
     * Max(0, quantity - reserved - fulfilled pending): what checkout can still reserve.
     *
     * @return string
     */
    public function getAvailable(): string;

    /**
     * Max(0, reserved + fulfilled pending - quantity): units promised beyond the snapshot.
     *
     * @return string
     */
    public function getShortage(): string;

    /**
     * Supplier purchase cost.
     *
     * @return string|null
     */
    public function getUnitCost(): ?string;

    /**
     * Get currency.
     *
     * @return string|null
     */
    public function getCurrency(): ?string;

    /**
     * Last applied update id.
     *
     * @return int
     */
    public function getLastUpdateId(): int;

    /**
     * Last applied at (database time).
     *
     * @return string|null
     */
    public function getLastAppliedAt(): ?string;
}
