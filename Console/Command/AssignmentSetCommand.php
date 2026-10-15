<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Api\AssignmentManagementInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:assignment:set
 */
class AssignmentSetCommand extends Command
{
    /**
     * __construct.
     *
     * @param AssignmentManagementInterface $assignmentManagement
     */
    public function __construct(private readonly AssignmentManagementInterface $assignmentManagement)
    {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:assignment:set')
            ->setDescription('Assign (or reassign) a SKU on a website to a supplier')
            ->addArgument('sku', InputArgument::REQUIRED, 'Simple product SKU')
            ->addArgument('website', InputArgument::REQUIRED, 'Website code')
            ->addArgument('supplier', InputArgument::REQUIRED, 'Supplier code');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->assignmentManagement->assign(
                (string)$input->getArgument('sku'),
                (string)$input->getArgument('website'),
                (string)$input->getArgument('supplier')
            );
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
        $output->writeln('Assignment saved. Existing reservations stay on their original supplier.');
        return Cli::RETURN_SUCCESS;
    }
}
