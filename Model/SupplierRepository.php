<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

use Company\SupplierSync\Api\Data\SupplierInterface;
use Company\SupplierSync\Api\Data\SupplierSearchResultsInterface;
use Company\SupplierSync\Api\Data\SupplierSearchResultsInterfaceFactory;
use Company\SupplierSync\Api\SupplierRepositoryInterface;
use Company\SupplierSync\Model\ResourceModel\Supplier as SupplierResource;
use Company\SupplierSync\Model\ResourceModel\Supplier\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Loads and saves suppliers. Codes are unique case-insensitively (UNIQUE(code) with a case-insensitive collation),
 * so ACME and acme are the same supplier and lookups by code ignore case.
 */
class SupplierRepository implements SupplierRepositoryInterface
{
    /**
     * __construct.
     *
     * @param SupplierResource $resource
     * @param SupplierFactory $supplierFactory
     * @param CollectionFactory $collectionFactory
     * @param CollectionProcessorInterface $collectionProcessor
     * @param SupplierSearchResultsInterfaceFactory $searchResultsFactory
     */
    public function __construct(
        private readonly SupplierResource $resource,
        private readonly SupplierFactory $supplierFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SupplierSearchResultsInterfaceFactory $searchResultsFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(SupplierInterface $supplier): SupplierInterface
    {
        $code = trim($supplier->getCode());
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code)) {
            throw new CouldNotSaveException(
                __('Supplier code must be 1-64 characters: letters, digits, "_" or "-".')
            );
        }
        if (trim($supplier->getName()) === '') {
            throw new CouldNotSaveException(__('Supplier name is required.'));
        }
        $supplier->setCode($code);
        try {
            /** @var Supplier $supplier */
            $this->resource->save($supplier);
        } catch (AlreadyExistsException $e) {
            throw new AlreadyExistsException(__('A supplier with code "%1" already exists.', $code), $e);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the supplier.'), $e);
        }
        return $supplier;
    }

    /**
     * @inheritDoc
     */
    public function getById(int $supplierId): SupplierInterface
    {
        $supplier = $this->supplierFactory->create();
        $this->resource->load($supplier, $supplierId);
        if (!$supplier->getSupplierId()) {
            throw new NoSuchEntityException(__('Supplier %1 does not exist.', $supplierId));
        }
        return $supplier;
    }

    /**
     * @inheritDoc
     */
    public function getByCode(string $code): SupplierInterface
    {
        $supplier = $this->supplierFactory->create();
        $this->resource->load($supplier, $code, SupplierInterface::CODE);
        if (!$supplier->getSupplierId()) {
            throw new NoSuchEntityException(__('Supplier "%1" does not exist.', $code));
        }
        return $supplier;
    }

    /**
     * @inheritDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SupplierSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);
        $results = $this->searchResultsFactory->create();
        $results->setSearchCriteria($searchCriteria);
        /** @var SupplierInterface[] $items */
        $items = $collection->getItems();
        $results->setItems($items);
        $results->setTotalCount($collection->getSize());
        return $results;
    }
}
