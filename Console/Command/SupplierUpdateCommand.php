<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

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
 * supplier-sync:supplier:update
 */
class SupplierUpdateCommand extends Command
{
    private const REPEATABLE = InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY;

    /**
     * __construct.
     *
     * @param SupplierRepositoryInterface $supplierRepository
     * @param SupplierWebsiteManagementInterface $websiteManagement
     */
    public function __construct(
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
        $this->setName('supplier-sync:supplier:update')
            ->setDescription('Rename, enable or disable a supplier, or toggle it per website')
            ->addArgument('code', InputArgument::REQUIRED, 'Supplier code')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'New name')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable the supplier')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable the supplier (releases still happen)')
            ->addOption('enable-website', null, self::REPEATABLE, 'Website code')
            ->addOption('disable-website', null, self::REPEATABLE, 'Website code');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('enable') && $input->getOption('disable')) {
            $output->writeln('<error>Use either --enable or --disable.</error>');
            return Cli::RETURN_FAILURE;
        }
        try {
            $supplier = $this->supplierRepository->getByCode((string)$input->getArgument('code'));
            if ($input->getOption('name') !== null) {
                $supplier->setName((string)$input->getOption('name'));
            }
            if ($input->getOption('enable')) {
                $supplier->setIsActive(true);
            }
            if ($input->getOption('disable')) {
                $supplier->setIsActive(false);
            }
            $this->supplierRepository->save($supplier);
            foreach ((array)$input->getOption('enable-website') as $websiteCode) {
                $this->websiteManagement->setActive($supplier->getCode(), (string)$websiteCode, true);
            }
            foreach ((array)$input->getOption('disable-website') as $websiteCode) {
                $this->websiteManagement->setActive($supplier->getCode(), (string)$websiteCode, false);
            }
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
        $output->writeln(sprintf('Updated supplier "%s".', $supplier->getCode()));
        return Cli::RETURN_SUCCESS;
    }
}
