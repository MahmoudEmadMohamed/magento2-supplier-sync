<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Data;

use Company\SupplierSync\Api\Data\UpdateStatusInterface;

/**
 * Read-only view of a company_supplier_update row.
 */
class UpdateStatusView implements UpdateStatusInterface
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
    public function getUpdateId(): int
    {
        return (int)$this->data['update_id'];
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
    public function getIdempotencyKey(): string
    {
        return (string)$this->data['idempotency_key'];
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
    public function getUnitPrice(): string
    {
        return (string)$this->data['unit_cost'];
    }

    /**
     * @inheritDoc
     */
    public function getCurrency(): string
    {
        return (string)$this->data['currency'];
    }

    /**
     * @inheritDoc
     */
    public function getStatus(): string
    {
        return (string)$this->data['status'];
    }

    /**
     * @inheritDoc
     */
    public function getAttempts(): int
    {
        return (int)$this->data['attempts'];
    }

    /**
     * @inheritDoc
     */
    public function getAcceptedAt(): string
    {
        return (string)$this->data['accepted_at'];
    }

    /**
     * @inheritDoc
     */
    public function getNextAttemptAt(): ?string
    {
        return isset($this->data['next_attempt_at']) ? (string)$this->data['next_attempt_at'] : null;
    }

    /**
     * @inheritDoc
     */
    public function getProcessedAt(): ?string
    {
        return isset($this->data['processed_at']) ? (string)$this->data['processed_at'] : null;
    }

    /**
     * @inheritDoc
     */
    public function getErrorCode(): ?string
    {
        return isset($this->data['error_code']) ? (string)$this->data['error_code'] : null;
    }

    /**
     * @inheritDoc
     */
    public function getError(): ?string
    {
        return isset($this->data['safe_error']) ? (string)$this->data['safe_error'] : null;
    }
}
