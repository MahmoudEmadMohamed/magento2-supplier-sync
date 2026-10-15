<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Model\Update\UpdateStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:update:list
 */
class UpdateListCommand extends Command
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
        $this->setName('supplier-sync:update:list')
            ->setDescription('List recent stock updates')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status')
            ->addOption('sku', null, InputOption::VALUE_REQUIRED, 'Filter by SKU')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows', '20');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['u' => $this->resource->getTableName(UpdateStore::TABLE)], [
                'update_id', 'sku', 'quantity', 'status', 'attempts', 'accepted_at', 'next_attempt_at', 'error_code',
            ])
            ->join(
                ['s' => $this->resource->getTableName('company_supplier')],
                's.supplier_id = u.supplier_id',
                ['code']
            )
            ->order('u.update_id DESC')
            ->limit(max(1, (int)$input->getOption('limit')));
        if ($input->getOption('status') !== null) {
            $select->where('u.status = ?', (string)$input->getOption('status'));
        }
        if ($input->getOption('sku') !== null) {
            $select->where('u.sku = ?', (string)$input->getOption('sku'));
        }
        $table = new Table($output);
        $table->setHeaders(['ID', 'SKU', 'Qty', 'Status', 'Attempts', 'Accepted', 'Next attempt', 'Error', 'Supplier']);
        foreach ($connection->fetchAll($select) as $row) {
            $table->addRow(array_values($row));
        }
        $table->render();
        return Cli::RETURN_SUCCESS;
    }
}
