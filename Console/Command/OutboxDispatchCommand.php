<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Model\Queue\OutboxDispatcher;
use Company\SupplierSync\Model\Queue\UpdateSweeper;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:outbox:dispatch
 */
class OutboxDispatchCommand extends Command
{
    /**
     * @param UpdateSweeper $sweeper
     * @param OutboxDispatcher $dispatcher
     */
    public function __construct(
        private readonly UpdateSweeper $sweeper,
        private readonly OutboxDispatcher $dispatcher
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:outbox:dispatch')
            ->setDescription('Recover expired claims and lost messages, then publish due rows (as the cron does)');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $swept = $this->sweeper->sweep();
        [$published, $failed] = $this->dispatcher->dispatch();
        $output->writeln(sprintf(
            'Sweep: %d expired claims recovered, %d messages rearmed, %d skipped (locked or changed).',
            $swept['expired'],
            $swept['republished'],
            $swept['skipped']
        ));
        $output->writeln(sprintf('Dispatch: %d published, %d failed (will retry).', $published, $failed));
        return $failed ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }
}
