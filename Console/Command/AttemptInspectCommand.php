<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Model\Placement\AttemptStore;
use Company\SupplierSync\Model\Reservation\ReservationLedger;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:attempt:inspect
 */
class AttemptInspectCommand extends Command
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:attempt:inspect')
            ->setDescription('Show a placement attempt and its reservations')
            ->addArgument('attempt_id', InputArgument::REQUIRED, 'Attempt ID');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->resource->getConnection();
        $attemptId = (int)$input->getArgument('attempt_id');
        $attempt = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(AttemptStore::TABLE))
                ->where('attempt_id = ?', $attemptId)
        );
        if (!$attempt) {
            $output->writeln("<error>Attempt $attemptId does not exist.</error>");
            return Cli::RETURN_FAILURE;
        }
        $table = new Table($output);
        foreach ($attempt as $key => $value) {
            $table->addRow([$key, (string)$value]);
        }
        $table->render();
        $reservations = new Table($output);
        $reservations->setHeaders(['ID', 'SKU', 'Order item', 'Reserved', 'Fulfilled', 'Released', 'Status']);
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(ReservationLedger::TABLE), [
                    'reservation_id', 'sku', 'order_item_id', 'qty_reserved', 'qty_fulfilled', 'qty_released', 'status',
                ])
                ->where('attempt_id = ?', $attemptId)
        );
        foreach ($rows as $row) {
            $reservations->addRow(array_values($row));
        }
        $reservations->render();
        return Cli::RETURN_SUCCESS;
    }
}
