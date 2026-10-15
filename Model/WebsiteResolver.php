<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves website codes and base currencies.
 */
class WebsiteResolver
{
    /**
     * __construct.
     *
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Get id by code.
     *
     * @param string $websiteCode
     * @return int
     * @throws NoSuchEntityException
     */
    public function getIdByCode(string $websiteCode): int
    {
        $code = trim($websiteCode);
        foreach ($this->storeManager->getWebsites() as $website) {
            if ($website->getCode() === $code) {
                return (int)$website->getId();
            }
        }
        throw new NoSuchEntityException(__('Website "%1" does not exist.', $code));
    }

    /**
     * Get code by id.
     *
     * @param int $websiteId
     * @return string
     */
    public function getCodeById(int $websiteId): string
    {
        return (string)$this->storeManager->getWebsite($websiteId)->getCode();
    }

    /**
     * Base currency code configured for the website.
     *
     * @param int $websiteId
     * @return string
     */
    public function getBaseCurrency(int $websiteId): string
    {
        return strtoupper((string)$this->scopeConfig->getValue(
            Currency::XML_PATH_CURRENCY_BASE,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        ));
    }
}
