<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Data;

use Company\SupplierSync\Api\Data\UpdateReceiptInterface;

/**
 * Immutable stock update receipt.
 */
class UpdateReceipt implements UpdateReceiptInterface
{
    /**
     * @param int $updateId
     * @param string $status
     * @param bool $replayed
     */
    public function __construct(
        private readonly int $updateId,
        private readonly string $status,
        private readonly bool $replayed
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getUpdateId(): int
    {
        return $this->updateId;
    }

    /**
     * @inheritDoc
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @inheritDoc
     */
    public function getReplayed(): bool
    {
        return $this->replayed;
    }
}
