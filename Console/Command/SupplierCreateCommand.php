<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Api\Data\SupplierInterfaceFactory;
use Company\SupplierSync\Api\SupplierRepositoryInterface;
use Company\SupplierSync\Api\SupplierWebsiteManagementInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:supplier:create
 */
class SupplierCreateCommand extends Command
{
    /**
     * __construct.
     *
     * @param SupplierInterfaceFactory $supplierFactory
     * @param SupplierRepositoryInterface $supplierRepository
     * @param SupplierWebsiteManagementInterface $websiteManagement
     */
    public function __construct(
        private readonly SupplierInterfaceFactory $supplierFactory,
        private readonly SupplierRepositoryInterface $supplierRepository,
        private readonly SupplierWebsiteManagementInterface $websiteManagement
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:supplier:create')
            ->setDescription('Create a supplier and enable it for websites')
            ->addArgument('code', InputArgument::REQUIRED, 'Supplier code (letters, digits, _ or -)')
            ->addArgument('name', InputArgument::REQUIRED, 'Supplier name')
            ->addOption(
                'website',
                'w',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Website code to enable (repeatable)'
            )
            ->addOption('inactive', null, InputOption::VALUE_NONE, 'Create the supplier disabled');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $supplier = $this->supplierFactory->create()
                ->setCode((string)$input->getArgument('code'))
                ->setName((string)$input->getArgument('name'))
                ->setIsActive(!$input->getOption('inactive'));
            $supplier = $this->supplierRepository->save($supplier);
            foreach ((array)$input->getOption('website') as $websiteCode) {
                $this->websiteManagement->setActive($supplier->getCode(), (string)$websiteCode, true);
            }
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
        $output->writeln(sprintf('Created supplier "%s" (id %d).', $supplier->getCode(), $supplier->getSupplierId()));
        return Cli::RETURN_SUCCESS;
    }
}
