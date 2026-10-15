<?php
/**
 * Copyright © Company. All rights reserved.
 *
 * Child process for scenario harnesses. Waits for a shared start time, does one thing, prints one JSON line.
 *
 *   php worker.php <magento_root> <start_at> process <update_id>
 *   php worker.php <magento_root> <start_at> submit <supplier> <key> <sku> <qty> <price> <currency> <website>
 *   php worker.php <magento_root> <start_at> reconcile <order_id>
 *   php worker.php <magento_root> <start_at> submit-quote <quote_id>   (admin-style placement; doubles active)
 */
declare(strict_types=1);

// phpcs:ignoreFile -- standalone CLI test harness, not module runtime code.

[$script, $root, $startAt, $action] = array_slice($argv, 0, 4);
$args = array_slice($argv, 4);
require $root . '/app/bootstrap.php';
require_once __DIR__ . '/Harness.php';
$harness = new \Company\SupplierSync\Test\Scenario\Harness($root, 'unused');
if ($action === 'submit-quote') {
    $harness->useDoubles();
}
$om = $harness->om;
// Warm up the object graph and the DB connection before the barrier, so processes collide on the work itself.
$om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection()->fetchOne('SELECT 1');
$services = [
    'process' => \Company\SupplierSync\Model\Queue\UpdateConsumer::class,
    'submit' => \Company\SupplierSync\Api\StockUpdateManagementInterface::class,
    'reconcile' => \Company\SupplierSync\Model\Reservation\ReconcileService::class,
    'submit-quote' => \Magento\Quote\Model\QuoteManagement::class,
];
$service = $om->get($services[$action]);
usleep(max(0, (int)(((float)$startAt - microtime(true)) * 1e6)));
try {
    $result = match ($action) {
        'process' => $service->process((int)$args[0]),
        'submit' => (function () use ($service, $args) {
            $receipt = $service->submit(...$args);
            return ['update_id' => $receipt->getUpdateId(), 'replayed' => $receipt->getReplayed()];
        })(),
        'reconcile' => $service->reconcileOrder((int)$args[0]),
        'submit-quote' => $harness->submitQuote((int)$args[0]),
    };
    echo json_encode(['ok' => true, 'result' => $result]), "\n";
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]), "\n";
}
