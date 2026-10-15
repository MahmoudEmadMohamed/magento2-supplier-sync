<?php
/**
 * Copyright © Company. All rights reserved.
 *
 * Concurrency proof: N processes each try to reserve 1 unit of a supplier SKU that has STOCK units.
 *
 * Usage: php Test/Concurrency/reserve-burst.php [magento_root] [processes=20] [stock=10]
 *
 * Runs against the configured Magento database in developer mode. Fixture rows are committed (separate
 * processes cannot see uncommitted data), use synthetic quote ids, and are deleted at the end.
 * Exit code 0 means every assertion held.
 */
declare(strict_types=1);

// phpcs:ignoreFile -- standalone CLI test harness, not module runtime code: it must spawn processes and print.

$root = realpath($argv[1] ?? __DIR__ . '/../../../../../..');
$processes = (int)($argv[2] ?? 20);
$stock = (int)($argv[3] ?? 10);
require $root . '/app/bootstrap.php';

$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$db = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$sku = 'SUPPLIER-SYNC-BURST';
$supplierCode = 'burst-supplier';
$quoteBase = 900000000;

// Fixture: a simple product, an active supplier enabled for the website, an assignment and a snapshot of $stock.
$products = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
try {
    $products->get($sku);
} catch (\Magento\Framework\Exception\NoSuchEntityException) {
    $product = $om->create(\Magento\Catalog\Model\Product::class);
    $product->setSku($sku)->setName($sku)->setTypeId('simple')->setAttributeSetId(4)->setPrice(1)
        ->setWebsiteIds([1])->setStatus(1)->setVisibility(1);
    $products->save($product);
}
$suppliers = $om->get(\Company\SupplierSync\Api\SupplierRepositoryInterface::class);
try {
    $supplier = $suppliers->getByCode($supplierCode);
} catch (\Magento\Framework\Exception\NoSuchEntityException) {
    $supplier = $suppliers->save($om->create(\Company\SupplierSync\Api\Data\SupplierInterface::class)
        ->setCode($supplierCode)->setName('Burst fixture')->setIsActive(true));
}
$supplierId = (int)$supplier->getSupplierId();
$om->get(\Company\SupplierSync\Api\SupplierWebsiteManagementInterface::class)->setActive($supplierCode, 'base', true);
$om->get(\Company\SupplierSync\Api\AssignmentManagementInterface::class)->assign($sku, 'base', $supplierCode);

$cleanup = function () use ($db, $supplierId, $sku, $quoteBase, $processes): void {
    $inventoryIds = $db->fetchCol('SELECT inventory_id FROM company_supplier_inventory WHERE supplier_id = ? AND sku = ?', [$supplierId, $sku]);
    $attemptIds = $db->fetchCol('SELECT attempt_id FROM company_supplier_placement_attempt WHERE quote_id BETWEEN ? AND ?', [$quoteBase, $quoteBase + $processes]);
    if ($inventoryIds) {
        $db->delete('company_supplier_reservation_event', ['inventory_id IN (?)' => $inventoryIds]);
        $db->delete('company_supplier_reservation', ['inventory_id IN (?)' => $inventoryIds]);
    }
    if ($attemptIds) {
        $db->delete('company_supplier_placement_attempt', ['attempt_id IN (?)' => $attemptIds]);
    }
    if ($inventoryIds) {
        $db->delete('company_supplier_inventory', ['inventory_id IN (?)' => $inventoryIds]);
    }
};
$cleanup();
$db->insert('company_supplier_inventory', [
    'supplier_id' => $supplierId, 'sku' => $sku, 'website_id' => 1, 'quantity' => $stock, 'last_update_id' => 0,
]);
$inventoryId = (int)$db->lastInsertId('company_supplier_inventory');

// Launch all processes; they sleep until a common start time, then contend on the same inventory row.
$startAt = microtime(true) + 3.0;
$handles = [];
for ($i = 1; $i <= $processes; $i++) {
    $cmd = [PHP_BINARY, $root . '/bin/magento', 'supplier-sync:dev:reserve-burst', '--sku=' . $sku, '--website=base',
        '--qty=1', '--quote-id=' . ($quoteBase + $i), '--start-at=' . $startAt];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $handles[] = [$proc, $pipes];
}
$ok = 0;
$failures = [];
foreach ($handles as [$proc, $pipes]) {
    $out = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    proc_close($proc);
    if (str_starts_with($out, 'OK ')) {
        $ok++;
    } else {
        $failures[] = $out;
    }
}

$row = $db->fetchRow('SELECT quantity, reserved_qty FROM company_supplier_inventory WHERE inventory_id = ?', [$inventoryId]);
$reservations = (int)$db->fetchOne('SELECT COUNT(*) FROM company_supplier_reservation WHERE inventory_id = ? AND status = ?', [$inventoryId, 'pending_placement']);
$heldQty = (string)$db->fetchOne('SELECT COALESCE(SUM(qty_reserved - qty_released), 0) FROM company_supplier_reservation WHERE inventory_id = ?', [$inventoryId]);
$reserveEvents = (int)$db->fetchOne('SELECT COUNT(*) FROM company_supplier_reservation_event WHERE inventory_id = ? AND type = ?', [$inventoryId, 'reserve']);
$releaseEvents = (int)$db->fetchOne('SELECT COUNT(*) FROM company_supplier_reservation_event WHERE inventory_id = ? AND type = ?', [$inventoryId, 'release']);
$failedAttempts = (int)$db->fetchOne('SELECT COUNT(*) FROM company_supplier_placement_attempt WHERE quote_id BETWEEN ? AND ? AND status = ?', [$quoteBase, $quoteBase + $processes, 'failed']);
$insufficient = count(array_filter($failures, fn ($f) => str_contains($f, 'InsufficientSupplierStockException')));

$expected = min($stock, $processes);
$checks = [
    "successes = $expected" => $ok === $expected,
    "reserved_qty = $expected" => bccomp((string)$row['reserved_qty'], (string)$expected, 4) === 0,
    "reserved_qty <= quantity" => bccomp((string)$row['reserved_qty'], (string)$row['quantity'], 4) <= 0,
    "held reservation rows = $expected" => $reservations === $expected,
    "sum(reserved - released) = reserved_qty" => bccomp($heldQty, (string)$row['reserved_qty'], 4) === 0,
    "reserve events = $expected" => $reserveEvents === $expected,
    'release events = 0 (losers never held stock)' => $releaseEvents === 0,
    'every loser failed for insufficient stock' => $insufficient === $processes - $expected,
    'loser attempts marked failed' => $failedAttempts === $processes - $expected,
];
printf("%d processes, stock %d: %d OK, %d failed; reserved_qty %s\n", $processes, $stock, $ok, count($failures), $row['reserved_qty']);
$passed = true;
foreach ($checks as $label => $result) {
    printf("  [%s] %s\n", $result ? 'PASS' : 'FAIL', $label);
    $passed = $passed && $result;
}
if (!$passed) {
    echo "Failures:\n  " . implode("\n  ", array_unique($failures)) . "\n";
}
$cleanup();
exit($passed ? 0 : 1);
