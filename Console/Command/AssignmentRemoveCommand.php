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
 * supplier-sync:assignment:remove
 */
class AssignmentRemoveCommand extends Command
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
        $this->setName('supplier-sync:assignment:remove')
            ->setDescription('Remove the supplier assignment of a SKU on a website')
            ->addArgument('sku', InputArgument::REQUIRED, 'SKU')
            ->addArgument('website', InputArgument::REQUIRED, 'Website code');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->assignmentManagement->unassign(
                (string)$input->getArgument('sku'),
                (string)$input->getArgument('website')
            );
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
        $output->writeln('Assignment removed. The SKU now follows normal Magento behaviour on that website.');
        return Cli::RETURN_SUCCESS;
    }
}
