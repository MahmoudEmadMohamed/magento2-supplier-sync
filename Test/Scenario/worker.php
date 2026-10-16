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
 *   php worker.php <magento_root> <start_at> ship <order_id>              (REST ShipOrder service, all items)
 *   php worker.php <magento_root> <start_at> sample-fulfil <inventory_id> <seconds>
 *       Repeatedly reads the committed fulfil event ids of one inventory row; prints the distinct samples.
 *   php worker.php <magento_root> <start_at> rest <mode> <METHOD> <path?query> <token|-> <json body|->
 *       Runs Magento's REST controller in the webapi_rest area (token auth, ACL, routing, error rendering).
 */
declare(strict_types=1);

// phpcs:ignoreFile -- standalone CLI test harness, not module runtime code.

[$script, $root, $startAt, $action] = array_slice($argv, 0, 4);
$args = array_slice($argv, 4);
require_once __DIR__ . '/Harness.php';
\Company\SupplierSync\Test\Scenario\Harness::requireRuntimeDi($root);
if ($action === 'rest') {
    [$mode, $method, $uri, $token, $body] = $args;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/rest' . $uri;
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    if ($token !== '-') {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    require $root . '/app/bootstrap.php';
    $om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
    $om->get(\Magento\Framework\App\State::class)->setAreaCode('webapi_rest');
    $om->configure($om->get(\Magento\Framework\App\ObjectManager\ConfigLoader::class)->load('webapi_rest'));
    require_once __DIR__ . '/Doubles.php';
    $om->configure(['preferences' => [
        \Magento\Integration\Model\Config\AuthorizationConfig::class
            => \Company\SupplierSync\Test\Scenario\BearerEnabledAuthorizationConfig::class,
    ]]);
    $state = $om->get(\Magento\Framework\App\State::class);
    (new ReflectionProperty($state, '_appMode'))->setValue($state, $mode);
    $request = $om->get(\Magento\Framework\Webapi\Rest\Request::class);
    [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
    parse_str($query, $params);
    $request->setPathInfo('/rest/default' . $path); // as an HTTP request to /rest/default/V1/... carries it
    $request->setQuery(new \Laminas\Stdlib\Parameters($params));
    if ($body !== '-') {
        $request->setContent($body);
    }
    $response = $om->get(\Magento\Webapi\Controller\Rest::class)->dispatch($request);
    ob_start();
    $response->sendResponse(); // renders errors exactly as over HTTP
    $output = (string)ob_get_clean();
    echo "\n", json_encode(['ok' => true, 'result' => [
        'code' => $response->getHttpResponseCode(),
        'body' => json_decode($output, true),
    ]]), "\n";
    exit(0);
}
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
    'ship' => \Magento\Sales\Api\ShipOrderInterface::class,
    'sample-fulfil' => \Magento\Framework\App\ResourceConnection::class,
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
        'ship' => (function () use ($service, $args) {
            $start = microtime(true);
            $service->execute((int)$args[0]);
            return ['start' => $start, 'end' => microtime(true)];
        })(),
        'sample-fulfil' => (function () use ($service, $args) {
            $connection = $service->getConnection();
            $until = microtime(true) + (float)$args[1];
            $samples = [];
            while (microtime(true) < $until) {
                // Autocommit: every read sees the latest committed rows.
                $ids = array_map('intval', $connection->fetchCol(
                    "SELECT event_id FROM company_supplier_reservation_event"
                    . " WHERE inventory_id = ? AND type = 'fulfil' ORDER BY event_id",
                    [(int)$args[0]]
                ));
                $samples[implode(',', $ids)] = $ids;
                usleep(2000);
            }
            return array_values($samples);
        })(),
    };
    echo json_encode(['ok' => true, 'result' => $result]), "\n";
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]), "\n";
}
