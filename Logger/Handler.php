<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

/**
 * Writes to var/log/supplier_sync.log.
 *
 * Callers log ids, codes and statuses only: never tokens, headers, payloads, customer or payment data.
 */
class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/supplier_sync.log';

    /**
     * @var int
     */
    protected $loggerType = Logger::INFO;
}
