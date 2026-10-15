<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Model\Placement\AttemptResolver;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:attempt:resolve
 */
class AttemptResolveCommand extends Command
{
    /**
     * @param AttemptResolver $attemptResolver
     */
    public function __construct(private readonly AttemptResolver $attemptResolver)
    {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:attempt:resolve')
            ->setDescription('Release the hold of a detached paying attempt after checking the payment outcome')
            ->addArgument('attempt_id', InputArgument::REQUIRED, 'Attempt ID')
            ->addOption('release', null, InputOption::VALUE_NONE, 'Release the hold; a late commit is then rejected');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('release')) {
            $output->writeln('<error>Pass --release to confirm. Check the payment gateway first.</error>');
            return Cli::RETURN_FAILURE;
        }
        if (!$this->attemptResolver->operatorRelease((int)$input->getArgument('attempt_id'))) {
            $output->writeln('<error>Only detached paying attempts can be released.</error>');
            return Cli::RETURN_FAILURE;
        }
        $output->writeln('Hold released. A late commit of the original order will be rejected.');
        return Cli::RETURN_SUCCESS;
    }
}
