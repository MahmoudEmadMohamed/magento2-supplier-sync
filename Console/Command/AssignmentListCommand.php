<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Api\AssignmentManagementInterface;
use Company\SupplierSync\Model\WebsiteResolver;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:assignment:list
 */
class AssignmentListCommand extends Command
{
    /**
     * __construct.
     *
     * @param AssignmentManagementInterface $assignmentManagement
     * @param WebsiteResolver $websiteResolver
     */
    public function __construct(
        private readonly AssignmentManagementInterface $assignmentManagement,
        private readonly WebsiteResolver $websiteResolver
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:assignment:list')
            ->setDescription('List supplier assignments')
            ->addOption('sku', null, InputOption::VALUE_REQUIRED, 'Filter by SKU');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sku = $input->getOption('sku');
        $table = new Table($output);
        $table->setHeaders(['SKU', 'Website', 'Supplier']);
        foreach ($this->assignmentManagement->getList($sku === null ? null : (string)$sku) as $row) {
            $website = $this->websiteResolver->getCodeById((int)$row['website_id']);
            $table->addRow([$row['sku'], $website, $row['supplier_code']]);
        }
        $table->render();
        return Cli::RETURN_SUCCESS;
    }
}
