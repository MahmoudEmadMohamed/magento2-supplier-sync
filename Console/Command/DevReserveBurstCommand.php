<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Console\Command;

use Company\SupplierSync\Api\AssignmentManagementInterface;
use Company\SupplierSync\Model\Decimal;
use Company\SupplierSync\Model\Placement\PlacementService;
use Company\SupplierSync\Model\Reservation\ReservationLine;
use Company\SupplierSync\Model\WebsiteResolver;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * supplier-sync:dev:reserve-burst — one reservation through the real placement service, for concurrency proofs.
 *
 * Run many copies in parallel (scripts/reserve-burst.sh). Each prints exactly one line: "OK <attempt_id>" or
 * "FAIL <reason>". Refuses to run outside developer mode.
 */
class DevReserveBurstCommand extends Command
{
    /**
     * @param PlacementService $placementService
     * @param AssignmentManagementInterface $assignmentManagement
     * @param WebsiteResolver $websiteResolver
     * @param State $appState
     */
    public function __construct(
        private readonly PlacementService $placementService,
        private readonly AssignmentManagementInterface $assignmentManagement,
        private readonly WebsiteResolver $websiteResolver,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('supplier-sync:dev:reserve-burst')
            ->setDescription('[developer mode] Take one supplier hold for a synthetic quote (concurrency proof)')
            ->addOption('sku', null, InputOption::VALUE_REQUIRED, 'Assigned SKU')
            ->addOption('website', null, InputOption::VALUE_REQUIRED, 'Website code', 'base')
            ->addOption('qty', null, InputOption::VALUE_REQUIRED, 'Quantity', '1')
            ->addOption('quote-id', null, InputOption::VALUE_REQUIRED, 'Synthetic quote id (unique per process)')
            ->addOption('quote-item-id', null, InputOption::VALUE_REQUIRED, 'Quote item id (default: quote id)')
            ->addOption('start-at', null, InputOption::VALUE_REQUIRED, 'Unix time to wait for, so processes collide');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->appState->getMode() !== State::MODE_DEVELOPER) {
            $output->writeln('FAIL not in developer mode');
            return Cli::RETURN_FAILURE;
        }
        $startAt = (float)$input->getOption('start-at');
        if ($startAt > microtime(true)) {
            usleep((int)(($startAt - microtime(true)) * 1e6));
        }
        $sku = (string)$input->getOption('sku');
        $quoteId = (int)$input->getOption('quote-id');
        $quoteItemId = (int)($input->getOption('quote-item-id') ?? $quoteId);
        try {
            $websiteId = $this->websiteResolver->getIdByCode((string)$input->getOption('website'));
            $supplierId = $this->assignmentManagement->getAssignedSupplierId($sku, $websiteId);
            if ($supplierId === null || $quoteId <= 0) {
                $output->writeln('FAIL unassigned sku or missing --quote-id');
                return Cli::RETURN_FAILURE;
            }
            $line = new ReservationLine(
                $quoteItemId,
                $sku,
                $websiteId,
                $supplierId,
                Decimal::normalize((string)$input->getOption('qty'), Decimal::QTY_SCALE)
            );
            $attemptId = $this->placementService->begin($quoteId, 'burst-' . $quoteId, [$line]);
        } catch (\Throwable $e) {
            $output->writeln('FAIL ' . get_class($e) . ': ' . $e->getMessage());
            return Cli::RETURN_FAILURE;
        }
        $output->writeln('OK ' . $attemptId);
        return Cli::RETURN_SUCCESS;
    }
}
