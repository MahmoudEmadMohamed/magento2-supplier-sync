<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Api\SupplierRepositoryInterface;
use Company\SupplierSync\Api\SupplierWebsiteManagementInterface;
use Company\SupplierSync\Model\WebsiteResolver;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:supplier:list
 */
class SupplierListCommand extends Command
{
    /**
     * __construct.
     *
     * @param SupplierRepositoryInterface $supplierRepository
     * @param SupplierWebsiteManagementInterface $websiteManagement
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param WebsiteResolver $websiteResolver
     */
    public function __construct(
        private readonly SupplierRepositoryInterface $supplierRepository,
        private readonly SupplierWebsiteManagementInterface $websiteManagement,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly WebsiteResolver $websiteResolver
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:supplier:list')->setDescription('List suppliers and their websites');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['ID', 'Code', 'Name', 'Active', 'Websites (active)']);
        foreach ($this->supplierRepository->getList($this->searchCriteriaBuilder->create())->getItems() as $supplier) {
            $websites = [];
            foreach ($this->websiteManagement->getWebsites((int)$supplier->getSupplierId()) as $websiteId => $active) {
                $websites[] = $this->websiteResolver->getCodeById((int)$websiteId) . ($active ? '' : ' (disabled)');
            }
            $table->addRow([
                $supplier->getSupplierId(),
                $supplier->getCode(),
                $supplier->getName(),
                $supplier->getIsActive() ? 'yes' : 'no',
                implode(', ', $websites),
            ]);
        }
        $table->render();
        return Cli::RETURN_SUCCESS;
    }
}
