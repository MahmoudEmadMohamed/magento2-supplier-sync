<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Api\InventoryQueryInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:inventory:show
 */
class InventoryShowCommand extends Command
{
    /**
     * @param InventoryQueryInterface $inventoryQuery
     */
    public function __construct(private readonly InventoryQueryInterface $inventoryQuery)
    {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:inventory:show')
            ->setDescription('Show supplier inventory counters, availability and shortage for a SKU')
            ->addArgument('sku', InputArgument::REQUIRED, 'SKU')
            ->addOption('website', null, InputOption::VALUE_REQUIRED, 'Website code', 'base');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $views = $this->inventoryQuery->get(
                (string)$input->getArgument('sku'),
                (string)$input->getOption('website')
            );
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
        $table = new Table($output);
        $table->setHeaders([
            'Supplier', 'Assigned', 'Quantity', 'Reserved', 'Fulfilled pending', 'Available', 'Shortage',
            'Unit cost', 'Last update',
        ]);
        foreach ($views as $view) {
            $table->addRow([
                $view->getSupplierCode(),
                $view->getIsAssigned() ? 'yes' : 'no',
                $view->getQuantity(),
                $view->getReservedQty(),
                $view->getFulfilledPendingQty(),
                $view->getAvailable(),
                $view->getShortage(),
                trim(($view->getUnitCost() ?? '') . ' ' . ($view->getCurrency() ?? '')),
                $view->getLastUpdateId(),
            ]);
        }
        $table->render();
        return Cli::RETURN_SUCCESS;
    }
}
