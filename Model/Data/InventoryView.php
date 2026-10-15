<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Data;

use Company\SupplierSync\Api\Data\InventoryViewInterface;

/**
 * Read-only view of a company_supplier_inventory row with derived availability.
 */
class InventoryView implements InventoryViewInterface
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * @inheritDoc
     */
    public function getSupplierCode(): string
    {
        return (string)$this->data['supplier_code'];
    }

    /**
     * @inheritDoc
     */
    public function getIsAssigned(): bool
    {
        return (bool)$this->data['is_assigned'];
    }

    /**
     * @inheritDoc
     */
    public function getSku(): string
    {
        return (string)$this->data['sku'];
    }

    /**
     * @inheritDoc
     */
    public function getWebsiteCode(): string
    {
        return (string)$this->data['website_code'];
    }

    /**
     * @inheritDoc
     */
    public function getQuantity(): string
    {
        return (string)$this->data['quantity'];
    }

    /**
     * @inheritDoc
     */
    public function getReservedQty(): string
    {
        return (string)$this->data['reserved_qty'];
    }

    /**
     * @inheritDoc
     */
    public function getFulfilledPendingQty(): string
    {
        return (string)$this->data['fulfilled_pending_qty'];
    }

    /**
     * @inheritDoc
     */
    public function getAvailable(): string
    {
        return (string)$this->data['available'];
    }

    /**
     * @inheritDoc
     */
    public function getShortage(): string
    {
        return (string)$this->data['shortage'];
    }

    /**
     * @inheritDoc
     */
    public function getUnitCost(): ?string
    {
        return isset($this->data['unit_cost']) ? (string)$this->data['unit_cost'] : null;
    }

    /**
     * @inheritDoc
     */
    public function getCurrency(): ?string
    {
        return isset($this->data['currency']) ? (string)$this->data['currency'] : null;
    }

    /**
     * @inheritDoc
     */
    public function getLastUpdateId(): int
    {
        return (int)$this->data['last_update_id'];
    }

    /**
     * @inheritDoc
     */
    public function getLastAppliedAt(): ?string
    {
        return isset($this->data['last_applied_at']) ? (string)$this->data['last_applied_at'] : null;
    }
}
