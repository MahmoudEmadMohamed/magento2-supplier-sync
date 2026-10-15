<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Model\Update\UpdateRecovery;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:update:retry
 */
class UpdateRetryCommand extends Command
{
    /**
     * @param UpdateRecovery $recovery
     */
    public function __construct(private readonly UpdateRecovery $recovery)
    {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:update:retry')
            ->setDescription('Requeue a failed update (or a rejected one with --force) with a fresh attempt budget')
            ->addArgument('update_id', InputArgument::REQUIRED, 'Update ID')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Also retry a rejected update');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->recovery->retry((int)$input->getArgument('update_id'), (bool)$input->getOption('force'));
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
        $output->writeln('Update requeued.');
        return Cli::RETURN_SUCCESS;
    }
}
