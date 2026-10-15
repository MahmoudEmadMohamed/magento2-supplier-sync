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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:update:inspect
 */
class UpdateInspectCommand extends Command
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
        $this->setName('supplier-sync:update:inspect')
            ->setDescription('Show a stock update, its outbox row and its audit trail')
            ->addArgument('update_id', InputArgument::REQUIRED, 'Update ID');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->resource->getConnection();
        $updateId = (int)$input->getArgument('update_id');
        $update = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(UpdateStore::TABLE))
                ->where('update_id = ?', $updateId)
        );
        if (!$update) {
            $output->writeln("<error>Stock update $updateId does not exist.</error>");
            return Cli::RETURN_FAILURE;
        }
        unset($update['claim_token']);
        $outbox = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(UpdateStore::OUTBOX_TABLE), [
                    'published_at', 'next_publish_at', 'claimed_until', 'attempts',
                ])
                ->where('update_id = ?', $updateId)
        ) ?: [];
        $table = new Table($output);
        foreach ($update as $key => $value) {
            $table->addRow([$key, (string)$value]);
        }
        foreach ($outbox as $key => $value) {
            $table->addRow(['outbox.' . $key, (string)$value]);
        }
        $table->render();

        $events = new Table($output);
        $events->setHeaders(['At', 'From', 'To', 'Attempt', 'Message']);
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(UpdateStore::EVENT_TABLE), [
                    'created_at', 'from_status', 'to_status', 'attempt', 'safe_message',
                ])
                ->where('update_id = ?', $updateId)
                ->order('event_id')
        );
        foreach ($rows as $row) {
            $events->addRow(array_values($row));
        }
        $events->render();
        return Cli::RETURN_SUCCESS;
    }
}
