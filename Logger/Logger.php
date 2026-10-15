<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Logger;

use Monolog\Logger as MonologLogger;
use Psr\Log\AbstractLogger;

/**
 * Module logger writing only to var/log/supplier_sync.log.
 *
 * Wraps its own Monolog instance instead of being a virtual type of Magento's logger: a virtual type inherits core's
 * handlers, so every module message would also land in system.log and exception.log.
 */
class Logger extends AbstractLogger
{
    /**
     * @var MonologLogger
     */
    private MonologLogger $logger;

    /**
     * @param Handler $handler
     */
    public function __construct(Handler $handler)
    {
        $this->logger = new MonologLogger('supplier_sync', [$handler]);
    }

    /**
     * @inheritDoc
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
