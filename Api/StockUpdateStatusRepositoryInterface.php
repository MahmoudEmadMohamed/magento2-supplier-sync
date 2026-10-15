<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Api;

/**
 * Read the processing status of an accepted stock update.
 *
 * @api
 */
interface StockUpdateStatusRepositoryInterface
{
    /**
     * Get the status of an accepted update.
     *
     * @param int $updateId
     * @return \Company\SupplierSync\Api\Data\UpdateStatusInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get(int $updateId): Data\UpdateStatusInterface;
}
