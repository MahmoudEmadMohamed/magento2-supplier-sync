<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Module configuration (supplier_sync/*).
 */
class Config
{
    /**
     * __construct.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * Fulfil events younger than this (relative to update acceptance) always count as pending.
     *
     * @return int
     */
    public function getSnapshotMaxLagSeconds(): int
    {
        return max(0, (int)$this->scopeConfig->getValue('supplier_sync/snapshot/max_lag_seconds'));
    }

    /**
     * Get queue lease seconds.
     *
     * @return int
     */
    public function getQueueLeaseSeconds(): int
    {
        return max(1, (int)$this->scopeConfig->getValue('supplier_sync/queue/lease_seconds'));
    }

    /**
     * Get max attempts.
     *
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return max(1, (int)$this->scopeConfig->getValue('supplier_sync/queue/max_attempts'));
    }

    /**
     * Get placement lease seconds.
     *
     * @return int
     */
    public function getPlacementLeaseSeconds(): int
    {
        return max(60, (int)$this->scopeConfig->getValue('supplier_sync/placement/lease_seconds'));
    }

    /**
     * Whether Magento is configured for asynchronous order placement (unsupported).
     *
     * @return bool
     */
    public function isAsyncOrderPlacementEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue('checkout/async');
    }
}
