<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api;

use Company\SupplierSync\Api\Data\SupplierInterface;
use Company\SupplierSync\Api\Data\SupplierSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * @api
 */
interface SupplierRepositoryInterface
{
    /**
     * Save.
     *
     * @param \Company\SupplierSync\Api\Data\SupplierInterface $supplier
     * @return \Company\SupplierSync\Api\Data\SupplierInterface
     * @throws AlreadyExistsException when the code is taken (enforced by UNIQUE(code))
     * @throws CouldNotSaveException
     */
    public function save(SupplierInterface $supplier): SupplierInterface;

    /**
     * Get by id.
     *
     * @param int $supplierId
     * @return \Company\SupplierSync\Api\Data\SupplierInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $supplierId): SupplierInterface;

    /**
     * Get by code.
     *
     * @param string $code
     * @return \Company\SupplierSync\Api\Data\SupplierInterface
     * @throws NoSuchEntityException
     */
    public function getByCode(string $code): SupplierInterface;

    /**
     * Get list.
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Company\SupplierSync\Api\Data\SupplierSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SupplierSearchResultsInterface;
}
