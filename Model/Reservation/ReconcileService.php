<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Reservation;

use Company\SupplierSync\Model\Db\TransactionRunner;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Reconciles an order's supplier reservations with its persisted quantities, in a module-owned transaction.
 */
class ReconcileService
{
    /**
     * @param TransactionRunner $transactionRunner
     * @param ReservationLedger $reservationLedger
     */
    public function __construct(
        private readonly TransactionRunner $transactionRunner,
        private readonly ReservationLedger $reservationLedger
    ) {
    }

    /**
     * Reconcile one order. Idempotent: calling it again with unchanged order data changes nothing.
     *
     * @param int $orderId
     * @return int Number of reservations changed
     */
    public function reconcileOrder(int $orderId): int
    {
        return $this->transactionRunner->run(
            fn (AdapterInterface $connection): int => $this->reservationLedger->reconcileOrder($connection, $orderId)
        );
    }
}
