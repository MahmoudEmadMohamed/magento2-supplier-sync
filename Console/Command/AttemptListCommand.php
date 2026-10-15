<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Model\Placement\AttemptStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:attempt:list
 */
class AttemptListCommand extends Command
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
        $this->setName('supplier-sync:attempt:list')
            ->setDescription('List placement attempts (use --detached for the ones waiting on an operator)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status')
            ->addOption('detached', null, InputOption::VALUE_NONE, 'Only detached paying attempts')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows', '20');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(AttemptStore::TABLE), [
                'attempt_id', 'quote_id', 'reserved_order_id', 'order_id', 'status', 'lease_until', 'detached_at',
                'started_at',
            ])
            ->order('attempt_id DESC')
            ->limit(max(1, (int)$input->getOption('limit')));
        if ($input->getOption('status') !== null) {
            $select->where('status = ?', (string)$input->getOption('status'));
        }
        if ($input->getOption('detached')) {
            $select->where('detached_at IS NOT NULL')->where('status = ?', 'paying');
        }
        $table = new Table($output);
        $table->setHeaders(['ID', 'Quote', 'Increment', 'Order', 'Status', 'Lease until', 'Detached', 'Started']);
        foreach ($connection->fetchAll($select) as $row) {
            $table->addRow(array_values($row));
        }
        $table->render();
        return Cli::RETURN_SUCCESS;
    }
}
