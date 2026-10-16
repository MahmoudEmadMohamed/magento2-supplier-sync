<?php
/**
 * Copyright © Company. All rights reserved.
 *
 * REST scenarios through Magento's real REST controller: integration token auth, ACL, routing, JSON parsing,
 * responses and error rendering. Each request runs in its own process (worker.php rest ...).
 *
 * Usage: php Test/Scenario/rest-scenarios.php [magento_root]
 */
declare(strict_types=1);

// phpcs:ignoreFile -- standalone CLI test harness, not module runtime code.

use Company\SupplierSync\Test\Scenario\Harness;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\Integration;

require __DIR__ . '/Harness.php';
$h = new Harness(realpath($argv[1] ?? __DIR__ . '/../../../../../..'), 'scenario-rest');
$sku = 'SUPPLIER-SYNC-R';
$decimalSku = 'SUPPLIER-SYNC-RD';
$h->product($sku);
$h->product($decimalSku, true);
$h->product('SUPPLIER-SYNC-RU');                    // exists, never assigned
$h->product('SUPPLIER-SYNC-RNW', false, []);        // in no website
$h->configurable('SUPPLIER-SYNC-RC', ['S']);       // not a simple product
$h->cleanup();
$h->dropSecondWebsite();
$secondWebsite = $h->secondWebsite();
$h->product('SUPPLIER-SYNC-RW', false, [1, $secondWebsite]);
$h->supplier($sku, $decimalSku, 'SUPPLIER-SYNC-RW');
$alt = $h->alternateSupplier();
$assignments = $h->om->get(\Company\SupplierSync\Api\AssignmentManagementInterface::class);
$assignments->assign('SUPPLIER-SYNC-RW', 'ss_second', $alt);
$websites = $h->om->get(\Company\SupplierSync\Api\SupplierWebsiteManagementInterface::class);
$websites->setActive($alt, 'ss_second', true);

$integrations = $h->om->get(IntegrationServiceInterface::class);
$oauth = $h->om->get(OauthServiceInterface::class);
$token = function (string $name, array $resources) use ($integrations, $oauth): array {
    $existing = $integrations->findByName($name);
    if ($existing->getId()) {
        $integrations->delete((int)$existing->getId());
    }
    $integration = $integrations->create([
        'name' => $name,
        'email' => '',
        'resource' => $resources,
        'all_resources' => false,
        'status' => Integration::STATUS_ACTIVE,
        'setup_type' => Integration::TYPE_MANUAL,
    ]);
    $oauth->createAccessToken((int)$integration->getConsumerId(), true);
    $integration->setStatus(Integration::STATUS_ACTIVE)->save();
    return [(int)$integration->getId(), $oauth->getAccessToken((int)$integration->getConsumerId())->getToken()];
};
[$writerId, $writer] = $token('supplier-sync-scenario-writer', [
    'Company_SupplierSync::updates_write', 'Company_SupplierSync::updates_read', 'Company_SupplierSync::inventory_read',
]);
[$readerId, $reader] = $token('supplier-sync-scenario-reader', ['Company_SupplierSync::updates_read']);

$key = 'rest-' . uniqid();
$body = fn (array $over = []) => json_encode(array_merge([
    'supplier_code' => 'scenario-rest', 'idempotency_key' => $key, 'sku' => $sku,
    'quantity' => '12.5', 'unit_price' => '49.99', 'currency' => 'USD', 'website_code' => 'base',
], $over));
$rest = fn (string $mode, string $method, string $uri, ?string $tok, ?string $json) =>
    ['rest', $mode, $method, $uri, $tok ?? '-', $json ?? '-'];

$h->section('Authentication and ACL');
[$anon, $readOnly, $accepted] = array_map(fn ($r) => $r['result'] ?? $r, $h->parallel([
    $rest('production', 'POST', '/V1/supplier-sync/updates', null, $body()),
    $rest('production', 'POST', '/V1/supplier-sync/updates', $reader, $body()),
    $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(['quantity' => '12'])),
]));
$h->check('no token: 401', ($anon['code'] ?? 0) === 401, json_encode($anon));
// Magento answers a valid token without the ACL resource with 401 "consumer isn't authorized" (not 403).
$h->check('token without updates_write: 401 naming the missing resource', ($readOnly['code'] ?? 0) === 401 && ($readOnly['body']['parameters']['resources'] ?? '') === 'Company_SupplierSync::updates_write', json_encode($readOnly));
$h->check('writer token: 200 with a receipt', ($accepted['code'] ?? 0) === 200 && ($accepted['body']['replayed'] ?? null) === false, json_encode($accepted));
$updateId = (int)($accepted['body']['update_id'] ?? 0);

$h->section('Intake over HTTP');
[$replay, $conflict, $invalid, $status, $forbiddenInventory, $inventory, $devError] = array_map(fn ($r) => $r['result'] ?? $r, $h->parallel([
    $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(['quantity' => '12.0000'])),
    $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(['quantity' => '13'])),
    $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(['idempotency_key' => 'k2-' . uniqid(), 'unit_price' => '-1'])),
    $rest('production', 'GET', "/V1/supplier-sync/updates/$updateId", $reader, null),
    $rest('production', 'GET', "/V1/supplier-sync/inventory?sku=$sku&websiteCode=base", $reader, null),
    $rest('production', 'GET', "/V1/supplier-sync/inventory?sku=$sku&websiteCode=base", $writer, null),
    $rest('developer', 'POST', '/V1/supplier-sync/updates', $writer, $body(['idempotency_key' => 'bad key'])),
]));
$h->check('equivalent payload (12 vs 12.0000) replays the receipt', ($replay['code'] ?? 0) === 200 && ($replay['body']['update_id'] ?? 0) === $updateId && ($replay['body']['replayed'] ?? false) === true, json_encode($replay));
$h->check('same key, different payload: 409 idempotency_conflict', ($conflict['code'] ?? 0) === 409 && ($conflict['body']['parameters']['error_code'] ?? '') === 'idempotency_conflict', json_encode($conflict));
$h->check('invalid price: 400 invalid_price', ($invalid['code'] ?? 0) === 400 && ($invalid['body']['parameters']['error_code'] ?? '') === 'invalid_price', json_encode($invalid));
$h->check('production mode: no trace in error responses', !isset($conflict['body']['trace']) && !isset($invalid['body']['trace']));
$h->check('GET status with updates_read: 200 queued', ($status['code'] ?? 0) === 200 && ($status['body']['status'] ?? '') === 'queued' && ($status['body']['quantity'] ?? '') === '12.0000', json_encode($status));
$h->check('GET inventory without inventory_read: 401 naming the missing resource', ($forbiddenInventory['code'] ?? 0) === 401 && ($forbiddenInventory['body']['parameters']['resources'] ?? '') === 'Company_SupplierSync::inventory_read', json_encode($forbiddenInventory));
$h->check('GET inventory with inventory_read: 200 (no snapshot applied yet: empty)', ($inventory['code'] ?? 0) === 200 && $inventory['body'] === [], json_encode($inventory));
$h->check('developer mode adds a trace key (documented)', array_key_exists('trace', $devError['body'] ?? []), json_encode($devError));

$h->om->get(\Company\SupplierSync\Model\Queue\UpdateConsumer::class)->process($updateId);
[$status, $inventory, $missing] = array_map(fn ($r) => $r['result'] ?? $r, $h->parallel([
    $rest('production', 'GET', "/V1/supplier-sync/updates/$updateId", $writer, null),
    $rest('production', 'GET', "/V1/supplier-sync/inventory?sku=$sku&websiteCode=base", $writer, null),
    $rest('production', 'GET', '/V1/supplier-sync/updates/999999999', $writer, null),
]));
$h->check('after processing: status applied', ($status['body']['status'] ?? '') === 'applied', json_encode($status));
$row = $inventory['body'][0] ?? [];
$h->check('inventory shows quantity, available and cost', ($row['quantity'] ?? '') === '12.0000' && ($row['available'] ?? '') === '12.0000' && ($row['unit_cost'] ?? '') === '49.990000' && ($row['is_assigned'] ?? false) === true, json_encode($row));
$h->check('unknown update id: 404', ($missing['code'] ?? 0) === 404, json_encode($missing));

$h->section('Decimal quantities');
[$decimalOk, $decimalRefused] = array_map(fn ($r) => $r['result'] ?? $r, $h->parallel([
    $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(['idempotency_key' => 'd1-' . uniqid(), 'sku' => $decimalSku, 'quantity' => '1.5'])),
    $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(['idempotency_key' => 'd2-' . uniqid(), 'quantity' => '1.5'])),
]));
$h->check('1.5 accepted for a product that allows decimal quantities', ($decimalOk['code'] ?? 0) === 200, json_encode($decimalOk));
$h->check('1.5 refused for a product that does not: 400 invalid_qty', ($decimalRefused['code'] ?? 0) === 400 && ($decimalRefused['body']['parameters']['error_code'] ?? '') === 'invalid_qty', json_encode($decimalRefused));

$h->section('Validation matrix (each case with a new key)');
$cases = [
    'invalid_idempotency_key' => [400, ['idempotency_key' => 'bad key']],
    'invalid_qty (scale 5)' => [400, ['quantity' => '1.00001']],
    'invalid_qty (negative)' => [400, ['quantity' => '-1']],
    'invalid_price (scale 7)' => [400, ['unit_price' => '1.0000001']],
    'invalid_currency' => [400, ['currency' => 'US']],
    'currency_mismatch' => [400, ['currency' => 'EUR']],
    'unknown_supplier' => [400, ['supplier_code' => 'no-such-supplier']],
    'unknown_website' => [400, ['website_code' => 'nowhere']],
    'unknown_sku' => [400, ['sku' => 'NO-SUCH-SKU']],
    'unsupported_product_type' => [400, ['sku' => 'SUPPLIER-SYNC-RC']],
    'product_not_in_website' => [400, ['sku' => 'SUPPLIER-SYNC-RNW']],
    'unassigned_sku' => [400, ['sku' => 'SUPPLIER-SYNC-RU']],
    'assignment_mismatch' => [409, ['supplier_code' => $alt]],
    'website_disabled' => [400, ['sku' => 'SUPPLIER-SYNC-RW', 'website_code' => 'ss_second']],
];
$requests = [];
foreach ($cases as $label => [, $over]) {
    $requests[] = $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(
        $over + ['idempotency_key' => 'm-' . md5($label . uniqid()), 'quantity' => '12']
    ));
}
// Supplier codes are case-insensitive: the accepted key replays under SCENARIO-REST.
$requests[] = $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body([
    'supplier_code' => 'SCENARIO-REST', 'quantity' => '12',
]));
$results = array_map(fn ($r) => $r['result'] ?? $r, $h->parallel($requests));
foreach (array_keys($cases) as $i => $label) {
    [$code] = $cases[$label];
    $expected = strtok($label, ' ');
    $got = $results[$i];
    $h->check("$code $label", ($got['code'] ?? 0) === $code && ($got['body']['parameters']['error_code'] ?? '') === $expected, json_encode($got));
}
$replayUpper = end($results);
$h->check('upper-case supplier code replays the same key', ($replayUpper['code'] ?? 0) === 200 && ($replayUpper['body']['update_id'] ?? 0) === $updateId && ($replayUpper['body']['replayed'] ?? false) === true, json_encode($replayUpper));

$h->section('Disabled supplier');
$suppliers = $h->om->get(\Company\SupplierSync\Api\SupplierRepositoryInterface::class);
$suppliers->save($suppliers->getByCode('scenario-rest')->setIsActive(false));
[$replayDisabled, $newDisabled] = array_map(fn ($r) => $r['result'] ?? $r, $h->parallel([
    $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(['quantity' => '12'])),
    $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(['idempotency_key' => 'off-' . uniqid()])),
]));
$h->supplier();
$h->check('identical replay after disabling still returns the receipt', ($replayDisabled['code'] ?? 0) === 200 && ($replayDisabled['body']['replayed'] ?? false) === true, json_encode($replayDisabled));
$h->check('new key for a disabled supplier: 400 supplier_disabled', ($newDisabled['code'] ?? 0) === 400 && ($newDisabled['body']['parameters']['error_code'] ?? '') === 'supplier_disabled', json_encode($newDisabled));

$h->section('Website isolation');
[$baseUpdate, $secondUpdate] = array_map(fn ($r) => $r['result'] ?? $r, $h->parallel([
    $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(['idempotency_key' => 'w1-' . uniqid(), 'sku' => 'SUPPLIER-SYNC-RW', 'quantity' => '3'])),
    $rest('production', 'POST', '/V1/supplier-sync/updates', $writer, $body(['idempotency_key' => 'w2-' . uniqid(), 'supplier_code' => $alt, 'sku' => 'SUPPLIER-SYNC-RW', 'quantity' => '7', 'website_code' => 'ss_second'])),
]));
$h->check('same SKU accepted on two websites from their assigned suppliers', ($baseUpdate['code'] ?? 0) === 200 && ($secondUpdate['code'] ?? 0) === 200, json_encode([$baseUpdate, $secondUpdate]));
$consumer = $h->om->get(\Company\SupplierSync\Model\Queue\UpdateConsumer::class);
$consumer->process((int)($baseUpdate['body']['update_id'] ?? 0));
$consumer->process((int)($secondUpdate['body']['update_id'] ?? 0));
[$baseView, $secondView] = array_map(fn ($r) => $r['result'] ?? $r, $h->parallel([
    $rest('production', 'GET', '/V1/supplier-sync/inventory?sku=SUPPLIER-SYNC-RW&websiteCode=base', $writer, null),
    $rest('production', 'GET', '/V1/supplier-sync/inventory?sku=SUPPLIER-SYNC-RW&websiteCode=ss_second', $writer, null),
]));
$rowsOf = fn ($v) => array_map(fn ($r) => [$r['supplier_code'], $r['quantity']], $v['body'] ?? []);
$h->check('base sees only its own ledger (scenario-rest, 3)', $rowsOf($baseView) === [['scenario-rest', '3.0000']], json_encode($baseView));
$h->check('ss_second sees only its own ledger (alternate supplier, 7)', $rowsOf($secondView) === [[$alt, '7.0000']], json_encode($secondView));

$h->section('Supplier codes');
$newSupplier = fn (string $code) => $suppliers->save(
    $h->om->create(\Company\SupplierSync\Api\Data\SupplierInterface::class)->setCode($code)->setName('Code test')->setIsActive(true)
);
$error = function (callable $fn): string {
    try {
        $fn();
        return '';
    } catch (\Throwable $e) {
        return get_class($e);
    }
};
$h->check('duplicate code is refused (UNIQUE)', str_ends_with($error(fn () => $newSupplier('scenario-rest')), 'AlreadyExistsException'));
$h->check('the same code in another case is a duplicate', str_ends_with($error(fn () => $newSupplier('SCENARIO-REST')), 'AlreadyExistsException'));
$h->check('invalid characters are refused', str_ends_with($error(fn () => $newSupplier('bad code')), 'CouldNotSaveException'));
$upper = $newSupplier('SCENARIO_REST_UPPER');
$h->check('upper-case codes like SUPPLIER_001 are accepted', (int)$upper->getSupplierId() > 0 && $suppliers->getByCode('scenario_rest_upper')->getSupplierId() === $upper->getSupplierId());
$h->db->delete('company_supplier', ['supplier_id = ?' => (int)$upper->getSupplierId()]);

$h->section('Safe logging');
$log = (string)@file_get_contents($h->root . '/var/log/supplier_sync.log');
$h->check('no bearer tokens in the module log', !str_contains($log, $writer) && !str_contains($log, $reader));
$h->check('no Authorization header values in the module log', stripos($log, 'bearer ') === false && stripos($log, 'authorization') === false);
$h->check('no customer e-mail addresses in the module log', !preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $log));
$h->check('no payloads in the module log (no idempotency keys or SKUs)', !str_contains($log, $key) && !str_contains($log, $sku));
$h->check('no payment data in the module log', stripos($log, 'checkmo') === false && stripos($log, 'cc_') === false);

$integrations->delete($writerId);
$integrations->delete($readerId);
$h->cleanup();
$h->dropSecondWebsite();
exit($h->finish());
