<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Placement;

use Company\SupplierSync\Model\AttemptStatus;
use Company\SupplierSync\Model\Reservation\ReconcileService;
use Company\SupplierSync\Model\Reservation\ReservationLedger;
use Company\SupplierSync\Model\ReservationStatus;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;

/**
 * Safety net run by cron: links saved orders, recovers expired attempts and re-reconciles open reservations.
 *
 * Every step is idempotent, so running it often, or concurrently with live traffic, is safe.
 *
 * Open orders are visited in order-id batches from a cursor kept in the flag table, wrapping to the start after the
 * last batch. Healthy open orders stay open, so a scan that always started at the beginning would revisit the same
 * oldest batch forever; with the cursor every open order is visited within ceil(open orders / limit) runs.
 */
class Reconciliation
{
    public const CURSOR_FLAG = 'supplier_sync_reconcile_cursor';

    /**
     * @param ResourceConnection $resource
     * @param AttemptResolver $attemptResolver
     * @param ReconcileService $reconcileService
     * @param FlagManager $flagManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AttemptResolver $attemptResolver,
        private readonly ReconcileService $reconcileService,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run all steps. Returns counts by step.
     *
     * @param int $limit
     * @param int|null $orderId Only reconcile this order
     * @return array{linked:int,released:int,detached:int,reconciled:int,errors:int}
     */
    public function run(int $limit = 200, ?int $orderId = null): array
    {
        $result = ['linked' => 0, 'released' => 0, 'detached' => 0, 'reconciled' => 0, 'errors' => 0];
        if ($orderId === null) {
            foreach ($this->attemptIds("status = 'order_saved'", $limit) as $attemptId) {
                $this->guard($result, function () use ($attemptId, &$result): void {
                    $linkedOrder = $this->attemptResolver->link($attemptId);
                    if ($linkedOrder !== null) {
                        $result['linked']++;
                        $this->reconcileService->reconcileOrder($linkedOrder);
                    }
                });
            }
            $expired = "status IN ('started', 'paying') AND lease_until < NOW() AND detached_at IS NULL";
            foreach ($this->attemptIds($expired, $limit) as $attemptId) {
                $this->guard($result, function () use ($attemptId, &$result): void {
                    $action = $this->attemptResolver->recover($attemptId);
                    if (isset($result[$action])) {
                        $result[$action]++;
                    }
                });
            }
        }
        foreach ($orderId !== null ? [$orderId] : $this->openOrderIds($limit) as $id) {
            $this->guard($result, function () use ($id, &$result): void {
                $result['reconciled'] += $this->reconcileService->reconcileOrder($id) > 0 ? 1 : 0;
            });
        }
        return $result;
    }

    /**
     * Run one step, counting and logging failures without stopping the others.
     *
     * @param array<string,int> $result
     * @param callable $step
     * @return void
     */
    private function guard(array &$result, callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            $result['errors']++;
            $this->logger->error('Supplier reconciliation step failed.', ['exception' => get_class($e)]);
        }
    }

    /**
     * Attempt ids matching a condition, oldest first.
     *
     * @param string $condition
     * @param int $limit
     * @return int[]
     */
    private function attemptIds(string $condition, int $limit): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName(AttemptStore::TABLE), ['attempt_id'])
                ->where($condition)
                ->order('attempt_id')
                ->limit($limit)
        ));
    }

    /**
     * Next batch of orders with open (linked, unsettled) reservations, continuing from the stored cursor.
     *
     * @param int $limit
     * @return int[]
     */
    private function openOrderIds(int $limit): array
    {
        $cursor = (int)$this->flagManager->getFlagData(self::CURSOR_FLAG);
        $connection = $this->resource->getConnection();
        $ids = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from($this->resource->getTableName(ReservationLedger::TABLE), ['order_id'])
                ->where('status = ?', ReservationStatus::OPEN)
                ->where('order_id > ?', $cursor)
                ->order('order_id')
                ->limit($limit)
        ));
        // A short batch means the end was reached: start from the beginning next time.
        $this->flagManager->saveFlag(self::CURSOR_FLAG, count($ids) < $limit ? 0 : (int)end($ids));
        return $ids;
    }
}
