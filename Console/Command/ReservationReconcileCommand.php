<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Model\Placement\Reconciliation;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:reservation:reconcile
 */
class ReservationReconcileCommand extends Command
{
    /**
     * @param Reconciliation $reconciliation
     */
    public function __construct(private readonly Reconciliation $reconciliation)
    {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:reservation:reconcile')
            ->setDescription('Link saved orders, recover expired attempts, reconcile open reservations')
            ->addOption('order', null, InputOption::VALUE_REQUIRED, 'Only reconcile this order id');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $orderId = $input->getOption('order');
        $result = $this->reconciliation->run(200, $orderId === null ? null : (int)$orderId);
        $output->writeln(sprintf(
            'Linked %d, released %d, detached %d, reconciled %d orders, %d errors.',
            $result['linked'],
            $result['released'],
            $result['detached'],
            $result['reconciled'],
            $result['errors']
        ));
        return $result['errors'] ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }
}
