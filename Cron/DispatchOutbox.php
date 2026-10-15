<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Cron;

use Company\SupplierSync\Model\Queue\OutboxDispatcher;
use Company\SupplierSync\Model\Queue\UpdateSweeper;

/**
 * Every minute: recover expired claims and lost messages, then publish due outbox rows.
 */
class DispatchOutbox
{
    /**
     * @param UpdateSweeper $sweeper
     * @param OutboxDispatcher $dispatcher
     */
    public function __construct(
        private readonly UpdateSweeper $sweeper,
        private readonly OutboxDispatcher $dispatcher
    ) {
    }

    /**
     * Run the sweep, then the dispatcher.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->sweeper->sweep();
        $this->dispatcher->dispatch();
    }
}
