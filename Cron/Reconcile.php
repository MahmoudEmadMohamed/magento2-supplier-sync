<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Cron;

use Company\SupplierSync\Model\Placement\Reconciliation;

/**
 * Every 5 minutes: link saved orders, recover expired placement attempts, re-reconcile open reservations.
 */
class Reconcile
{
    /**
     * @param Reconciliation $reconciliation
     */
    public function __construct(private readonly Reconciliation $reconciliation)
    {
    }

    /**
     * Run reconciliation.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->reconciliation->run();
    }
}
