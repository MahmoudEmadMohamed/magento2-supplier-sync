<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

use Company\SupplierSync\Api\Data\SupplierInterface;
use Company\SupplierSync\Model\ResourceModel\Supplier as SupplierResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Supplier model.
 */
class Supplier extends AbstractModel implements SupplierInterface
{
    /**
     * _construct.
     *
     * @var string
     */
    protected $_eventPrefix = 'company_supplier';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(SupplierResource::class);
    }

    /**
     * @inheritDoc
     */
    public function getSupplierId(): ?int
    {
        $id = $this->getData(self::SUPPLIER_ID);
        return $id === null ? null : (int)$id;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return (string)$this->getData(self::CODE);
    }

    /**
     * @inheritDoc
     */
    public function setCode(string $code): SupplierInterface
    {
        return $this->setData(self::CODE, $code);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return (string)$this->getData(self::NAME);
    }

    /**
     * @inheritDoc
     */
    public function setName(string $name): SupplierInterface
    {
        return $this->setData(self::NAME, $name);
    }

    /**
     * @inheritDoc
     */
    public function getIsActive(): bool
    {
        return (bool)$this->getData(self::IS_ACTIVE);
    }

    /**
     * @inheritDoc
     */
    public function setIsActive(bool $isActive): SupplierInterface
    {
        return $this->setData(self::IS_ACTIVE, (int)$isActive);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedAt(): ?string
    {
        return $this->getData(self::CREATED_AT);
    }

    /**
     * @inheritDoc
     */
    public function getUpdatedAt(): ?string
    {
        return $this->getData(self::UPDATED_AT);
    }
}
