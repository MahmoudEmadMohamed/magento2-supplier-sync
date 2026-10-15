<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api\Data;

/**
 * Supplier.
 *
 * @api
 */
interface SupplierInterface
{
    public const SUPPLIER_ID = 'supplier_id';
    public const CODE = 'code';
    public const NAME = 'name';
    public const IS_ACTIVE = 'is_active';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * Get supplier id.
     *
     * @return int|null
     */
    public function getSupplierId(): ?int;

    /**
     * Get code.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Set code.
     *
     * @param string $code
     * @return $this
     */
    public function setCode(string $code): self;

    /**
     * Get name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Set name.
     *
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self;

    /**
     * Get is active.
     *
     * @return bool
     */
    public function getIsActive(): bool;

    /**
     * Set is active.
     *
     * @param bool $isActive
     * @return $this
     */
    public function setIsActive(bool $isActive): self;

    /**
     * Get created at.
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * Get updated at.
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
