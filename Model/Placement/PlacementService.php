<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Placement;

use Company\SupplierSync\Model\AttemptStatus;
use Company\SupplierSync\Model\Db\TransactionRunner;
use Company\SupplierSync\Model\Reservation\ReservationLedger;
use Company\SupplierSync\Model\Reservation\ReservationLine;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Starts a placement attempt and takes its supplier holds, before payment.
 */
class PlacementService
{
    /**
     * @param TransactionRunner $transactionRunner
     * @param AttemptStore $attemptStore
     * @param AttemptResolver $attemptResolver
     * @param ReservationLedger $reservationLedger
     */
    public function __construct(
        private readonly TransactionRunner $transactionRunner,
        private readonly AttemptStore $attemptStore,
        private readonly AttemptResolver $attemptResolver,
        private readonly ReservationLedger $reservationLedger
    ) {
    }

    /**
     * Create an attempt for a quote and reserve all lines, all or nothing.
     *
     * @param int $quoteId
     * @param string|null $reservedOrderId
     * @param ReservationLine[] $lines
     * @return int Attempt id,in status started with its holds committed
     * @throws LocalizedException customer-safe; nothing is held when this throws
     */
    public function begin(int $quoteId, ?string $reservedOrderId, array $lines): int
    {
        $this->settlePriorAttempts($quoteId);
        $attemptId = $this->attemptStore->create($quoteId, $reservedOrderId);
        try {
            $this->transactionRunner->run(function (AdapterInterface $connection) use ($attemptId, $lines): void {
                $attempt = $this->attemptStore->lock($connection, $attemptId);
                if ($attempt === null || $attempt['status'] !== AttemptStatus::STARTED) {
                    throw new LocalizedException(__('The order could not be placed. Please try again.'));
                }
                $this->reservationLedger->reserve($connection, $attemptId, $lines);
            });
        } catch (\Throwable $e) {
            $this->attemptResolver->fail($attemptId, 'reservation_failed');
            throw $e;
        }
        return $attemptId;
    }

    /**
     * Refuse a concurrent submit of the same quote; recover earlier attempts whose lease expired.
     *
     * @param int $quoteId
     * @return void
     * @throws LocalizedException
     */
    private function settlePriorAttempts(int $quoteId): void
    {
        $this->transactionRunner->run(function (AdapterInterface $connection) use ($quoteId): void {
            foreach ($this->attemptStore->lockOpenForQuote($connection, $quoteId) as $attempt) {
                if (!(int)$attempt['lease_expired']) {
                    throw new LocalizedException(__('This order is already being placed. Please wait a moment.'));
                }
                $this->attemptResolver->recoverLocked($connection, $attempt);
            }
        });
    }
}
