<?php
/**
 * Copyright © Company. All rights reserved.
 *
 * Order placement, fences, recovery and order lifecycle scenarios, verified from persisted rows.
 * Orders are placed through Magento's real checkout services with check/money order and flat rate shipping.
 *
 * Usage: php Test/Scenario/placement-scenarios.php [magento_root]
 */
declare(strict_types=1);

// phpcs:ignoreFile -- standalone CLI test harness, not module runtime code.

use Company\SupplierSync\Model\Placement\AttemptContext;
use Company\SupplierSync\Model\Placement\AttemptResolver;
use Company\SupplierSync\Model\Placement\PlacementService;
use Company\SupplierSync\Model\Placement\Reconciliation;
use Company\SupplierSync\Model\Reservation\ReservationLine;
use Company\SupplierSync\Observer\PrePaymentFence;
use Company\SupplierSync\Test\Scenario\AdjustableConfig;
use Company\SupplierSync\Test\Scenario\CaptureThenFailPayment;
use Company\SupplierSync\Test\Scenario\FailingOrderRelation;
use Company\SupplierSync\Test\Scenario\Harness;
use Company\SupplierSync\Test\Scenario\SkippablePrePaymentFence;
use Company\SupplierSync\Test\Scenario\ThrowAfterCommitOrderRepository;

require __DIR__ . '/Harness.php';
$h = new Harness(realpath($argv[1] ?? __DIR__ . '/../../../../../..'), 'scenario-place');
$h->useDoubles();
$db = $h->db;
$om = $h->om;
$sku = 'SUPPLIER-SYNC-P';
$conf = 'SUPPLIER-SYNC-PC';
$h->product($sku);
$h->product('SUPPLIER-SYNC-UNMAPPED');
$snapSku = 'SUPPLIER-SYNC-S';
$h->product($snapSku);
$c = $h->configurable($conf, ['S', 'M']);
$super = fn (string $label) => [$c['attribute_id'] => $c['options'][$label]];
$h->cleanup();
$h->supplier($sku, "$conf-S", $snapSku);
AdjustableConfig::$maxLag = 0; // Worked examples assume shipments outside the lag window.

$inv = fn (string $s = 'SUPPLIER-SYNC-P') => $h->inventory($s);
$counters = fn (string $s = 'SUPPLIER-SYNC-P') => implode('/', [
    (float)$inv($s)['quantity'], (float)$inv($s)['reserved_qty'], (float)$inv($s)['fulfilled_pending_qty'],
]);
$try = function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};

$h->section('Successful placement');
$h->snapshot($sku, '10');
$q = $h->quote([['sku' => $sku, 'qty' => 3], ['sku' => 'SUPPLIER-SYNC-UNMAPPED', 'qty' => 1]]);
$orderId = $h->placeQuote($q);
$attempt = $h->attemptForQuote($q);
$res = $h->reservations(['order_id = ?' => $orderId]);
$h->check('order placed', $orderId > 0);
$h->check('attempt completed and bound to the order', $attempt['status'] === 'completed' && (int)$attempt['order_id'] === $orderId);
$h->check('one reservation (unmapped line keeps normal behaviour)', count($res) === 1);
$h->check('reservation linked to the order item', (int)$res[0]['order_item_id'] === $h->orderItemId($orderId, $sku) && $res[0]['status'] === 'open');
$h->check('ledger: 10 / reserved 3 / pending 0', $counters() === '10/3/0', $counters());

$h->section('Insufficient supplier stock fails before payment');
CaptureThenFailPayment::$captures = 0;
$q = $h->quote([['sku' => $sku, 'qty' => 8]]);
$error = $try(fn () => $h->placeQuote($q));
$h->check('placement refused with a customer-safe message', str_contains((string)$error, 'not available from the supplier'), (string)$error);
$h->check('no order row', $h->orderCountForQuote($q) === 0);
$h->check('attempt failed, nothing held', $h->attemptForQuote($q)['status'] === 'failed' && $counters() === '10/3/0');

$h->section('Configurable product: SKU from the child, quantity from the parent');
$h->snapshot("$conf-S", '5');
$q = $h->quote([['sku' => $conf, 'qty' => 2, 'super' => $super('S')]]);
$confOrder = $h->placeQuote($q);
$res = $h->reservations(['order_id = ?' => $confOrder]);
$parentItem = $h->orderItemId($confOrder, "$conf-S", 'configurable');
$h->check('reserved 2 (parent qty), not 1 (child quote qty)', count($res) === 1 && $res[0]['qty_reserved'] === '2.0000');
$h->check('linked to the child item, quantities read from the parent', (int)$res[0]['order_item_id'] === $h->orderItemId($confOrder, "$conf-S") && (int)$res[0]['qty_source_item_id'] === $parentItem);

$h->section('Same SKU on two lines is checked as one quantity');
$h->snapshot($sku, '10');
$q = $h->quote([['sku' => $sku, 'qty' => 4], ['sku' => $conf, 'qty' => 1, 'super' => $super('S')]]);
$h->check('setup: 7 available', (float)$h->om->get(\Company\SupplierSync\Api\InventoryQueryInterface::class)->get($sku, 'base')[0]->getAvailable() === 7.0);
$lines = [
    new ReservationLine(900001, $sku, 1, $h->supplierId, '4.0000'),
    new ReservationLine(900002, $sku, 1, $h->supplierId, '4.0000'),
];
$error = $try(fn () => $om->get(PlacementService::class)->begin(900000, null, $lines));
$h->check('4 + 4 > 7 is refused as a whole', $error !== null && $counters() === '10/3/0', (string)$error);

$h->section('Declined payment, then retry (Example 7)');
$q = $h->quote([['sku' => $sku, 'qty' => 2]]);
CaptureThenFailPayment::$fail = true;
$error = $try(fn () => $h->placeQuote($q));
CaptureThenFailPayment::$fail = false;
$first = $h->attemptForQuote($q);
$h->check('placement failed with the payment error', str_contains((string)$error, 'Simulated gateway'), (string)$error);
$h->check('attempt failed and hold released', $first['status'] === 'failed' && $counters() === '10/3/0');
$h->check('the simulated capture happened (payment reconciliation is out of scope)', CaptureThenFailPayment::$captures === 1);
$retryOrder = $h->placeQuote($q);
$h->check('retry of the same cart succeeds', $retryOrder > 0 && $h->attemptForQuote($q)['status'] === 'completed');
$h->check('retry holds 2 more', $counters() === '10/5/0', $counters());

$h->section('Order save fails after the pre-commit fence');
$q = $h->quote([['sku' => $sku, 'qty' => 1]]);
FailingOrderRelation::$fail = true;
$error = $try(fn () => $h->placeQuote($q));
FailingOrderRelation::$fail = false;
$h->check('no order row (rolled back with the fence write)', $h->orderCountForQuote($q) === 0, (string)$error);
$h->check('attempt failed, hold released', $h->attemptForQuote($q)['status'] === 'failed' && $counters() === '10/5/0');

$h->section('Exception after the order committed');
$q = $h->quote([['sku' => $sku, 'qty' => 1]]);
ThrowAfterCommitOrderRepository::$fail = true;
$error = $try(fn () => $h->placeQuote($q));
ThrowAfterCommitOrderRepository::$fail = false;
$attempt = $h->attemptForQuote($q);
$h->check('the order exists', $h->orderCountForQuote($q) === 1, (string)$error);
$h->check('hold linked, not released', $attempt['status'] === 'completed' && $counters() === '10/6/0', $attempt['status'] . ' ' . $counters());

$h->section('A path that skips the pre-payment fence cannot commit (I2)');
$q = $h->quote([['sku' => $sku, 'qty' => 1]]);
SkippablePrePaymentFence::$skip = true;
$error = $try(fn () => $h->placeQuote($q));
SkippablePrePaymentFence::$skip = false;
$h->check('pre-commit fence rejects a started attempt', $h->orderCountForQuote($q) === 0, (string)$error);
$h->check('hold released', $h->attemptForQuote($q)['status'] === 'failed' && $counters() === '10/6/0');

$h->section('Placement inside an open transaction is refused before any write');
$q = $h->quote([['sku' => $sku, 'qty' => 1]]);
$db->beginTransaction();
$error = $try(fn () => $h->placeQuote($q));
$db->rollBack();
$h->check('refused', str_contains((string)$error, 'existing transaction'), (string)$error);
$h->check('no attempt created', $h->attemptForQuote($q) === null);

$h->section('Concurrent double submit of one cart (admin path, no core cart mutex)');
$q = $h->quote([['sku' => $sku, 'qty' => 1]]);
$results = $h->parallel([['submit-quote', (string)$q], ['submit-quote', (string)$q]]);
$okCount = count(array_filter($results, fn ($r) => $r['ok']));
$h->check('exactly one placement succeeds', $okCount === 1, json_encode($results));
$h->check('exactly one order and one hold', $h->orderCountForQuote($q) === 1 && $counters() === '10/7/0', $counters());

$h->section('Recovery by attempt state (Example 8)');
$resolver = $om->get(AttemptResolver::class);
$recon = $om->get(Reconciliation::class);
$begin = fn (int $quoteId) => $om->get(PlacementService::class)
    ->begin($quoteId, null, [new ReservationLine($quoteId, $sku, 1, $h->supplierId, '2.0000')]);
$expire = fn (int $id) => $db->query('UPDATE company_supplier_placement_attempt SET lease_until = NOW() - INTERVAL 1 SECOND WHERE attempt_id = ?', [$id]);
$stamped = function (int $attemptId) use ($om) {
    $order = $om->create(\Magento\Sales\Model\Order::class);
    $order->setData(AttemptContext::ORDER_KEY, $attemptId);
    return new \Magento\Framework\Event\Observer(['event' => new \Magento\Framework\Event(['order' => $order])]);
};
$a = $begin(910001);
$expire($a);
$recon->run();
$h->check('expired started: released', $h->attempt($a)['status'] === 'failed' && $counters() === '10/7/0');
$error = $try(fn () => $om->get(PrePaymentFence::class)->execute($stamped($a)));
$h->check('resumed worker blocked at the pre-payment fence', $error !== null);

$a = $begin(910002);
$om->get(PrePaymentFence::class)->execute($stamped($a));
$expire($a);
$recon->run();
$row = $h->attempt($a);
$h->check('expired paying: detached, hold kept', $row['status'] === 'paying' && $row['detached_at'] !== null && $counters() === '10/9/0', $counters());
$h->check('detached hold no longer blocks the quote item', $h->reservations(['attempt_id = ?' => $a])[0]['active_quote_item_id'] === null);
$h->check('operator release frees it', $resolver->operatorRelease($a) && $counters() === '10/7/0');
$h->check('operator release only applies to detached paying attempts', !$resolver->operatorRelease($a));

$h->section('Slow payment: recovery, retry and the late commit (real processes)');
$h->snapshot($sku, '20'); // 20 - 7 reserved = 13 available
$q = $h->quote([['sku' => $sku, 'qty' => 2]]);
putenv('SUPPLIER_SYNC_PAYMENT_SLEEP=12');
$startAt = microtime(true);
$slow = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $h->root, (string)$startAt, 'submit-quote', (string)$q], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
putenv('SUPPLIER_SYNC_PAYMENT_SLEEP');
for ($i = 0; $i < 60 && (($h->attemptForQuote($q)['status'] ?? '') !== 'paying'); $i++) {
    usleep(250000);
}
$slowAttempt = (int)$h->attemptForQuote($q)['attempt_id'];
$expire($slowAttempt);
$recon->run();
$h->check('worker still in payment; its attempt is detached with the hold kept', $h->attempt($slowAttempt)['detached_at'] !== null && $counters() === '20/9/0', $counters());
$retry = $h->submitQuote($q);
$h->check('customer retry places a second order while the first is in flight', $retry > 0 && $counters() === '20/11/0', $counters());
$out = json_decode(trim(stream_get_contents($pipes[1])), true);
proc_close($slow);
$h->check('the slow worker then commits: accepted by the pre-commit fence (still paying)', ($out['ok'] ?? false) === true, json_encode($out));
$h->check('two orders for one cart (documented duplicate-order exposure)', $h->orderCountForQuote($q) === 2);
$recon->run();
$h->check('both orders linked; both holds counted, no oversell', $h->attempt($slowAttempt)['status'] === 'completed' && $counters() === '20/11/0', $h->attempt($slowAttempt)['status'] . ' ' . $counters());

$q = $h->quote([['sku' => $sku, 'qty' => 2]]);
putenv('SUPPLIER_SYNC_PAYMENT_SLEEP=12');
$slow = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $h->root, (string)microtime(true), 'submit-quote', (string)$q], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
putenv('SUPPLIER_SYNC_PAYMENT_SLEEP');
for ($i = 0; $i < 60 && (($h->attemptForQuote($q)['status'] ?? '') !== 'paying'); $i++) {
    usleep(250000);
}
$slowAttempt = (int)$h->attemptForQuote($q)['attempt_id'];
$expire($slowAttempt);
$recon->run();
$h->check('operator releases the detached attempt', $resolver->operatorRelease($slowAttempt) && $counters() === '20/11/0');
$out = json_decode(trim(stream_get_contents($pipes[1])), true);
proc_close($slow);
$h->check('late commit after operator release is rejected', ($out['ok'] ?? true) === false && $h->orderCountForQuote($q) === 0, json_encode($out));

$h->section('Order lifecycle: shipment, cancellation, refunds');
$h->snapshot($sku, '30');
$base = $counters();
[$qty, $reserved] = [30.0, (float)$inv()['reserved_qty']];
$ship = function (int $order, array $qtys) use ($om) {
    $items = [];
    foreach ($qtys as $itemId => $q) {
        $items[] = $om->create(\Magento\Sales\Api\Data\ShipmentItemCreationInterface::class)->setOrderItemId($itemId)->setQty($q);
    }
    $om->get(\Magento\Sales\Api\ShipOrderInterface::class)->execute($order, $items);
};
$q = $h->quote([['sku' => $sku, 'qty' => 5]]);
$o = $h->placeQuote($q);
$item = $h->orderItemId($o, $sku);
$r = function () use (&$o, $h) {
    return $h->reservations(['order_id = ?' => $o])[0];
};
$ship($o, [$item => 2]);
$h->check('partial shipment: fulfilled 2, still open', $r()['qty_fulfilled'] === '2.0000' && $r()['status'] === 'open');
$h->check('ledger moves 2 from reserved to fulfilled pending', (float)$inv()['reserved_qty'] === $reserved + 3 && (float)$inv()['fulfilled_pending_qty'] === 2.0);
$ship($o, [$item => 1]);
$h->check('second partial shipment (no order-level commit event) is still caught', $r()['qty_fulfilled'] === '3.0000');
$om->get(\Magento\Sales\Api\OrderManagementInterface::class)->cancel($o);
$h->check('cancel after partial shipment releases the unshipped 2', $r()['qty_released'] === '2.0000' && $r()['status'] === 'closed');
$h->check('ledger: reserved back to before, 3 fulfilled pending', (float)$inv()['reserved_qty'] === $reserved && (float)$inv()['fulfilled_pending_qty'] === 3.0);
$fulfilEvents = count($h->events((int)$inv()['inventory_id'], 'fulfil'));
$om->get(\Company\SupplierSync\Model\Reservation\ReconcileService::class)->reconcileOrder($o);
$om->get(\Company\SupplierSync\Model\Reservation\ReconcileService::class)->reconcileOrder($o);
$h->check('repeated reconcile is a no-op', count($h->events((int)$inv()['inventory_id'], 'fulfil')) === $fulfilEvents && $r()['qty_released'] === '2.0000');

$q = $h->quote([['sku' => $sku, 'qty' => 4]]);
$o = $h->placeQuote($q);
$results = $h->parallel(array_fill(0, 20, ['reconcile', (string)$o]));
$db->query("UPDATE sales_order_item SET qty_canceled = 4 WHERE order_id = ? AND sku = ?", [$o, $sku]);
$db->query("UPDATE sales_order SET state = 'canceled', status = 'canceled' WHERE entity_id = ?", [$o]);
$results = $h->parallel(array_fill(0, 20, ['reconcile', (string)$o]));
$releases = (int)$db->fetchOne("SELECT COUNT(*) FROM company_supplier_reservation_event e JOIN company_supplier_reservation r ON r.reservation_id = e.reservation_id WHERE r.order_id = ? AND e.type = 'release'", [$o]);
$h->check('20 concurrent reconciles of one cancellation: one release event', $releases === 1, "releases=$releases");
$h->check('20 concurrent reconciles: all succeeded', count(array_filter($results, fn ($x) => $x['ok'])) === 20);

$q = $h->quote([['sku' => $sku, 'qty' => 3]]);
$o = $h->placeQuote($q);
$item = $h->orderItemId($o, $sku);
$om->get(\Magento\Sales\Api\InvoiceOrderInterface::class)->execute($o, true);
$refundItem = $om->create(\Magento\Sales\Api\Data\CreditmemoItemCreationInterface::class)->setOrderItemId($item)->setQty(1);
$om->get(\Magento\Sales\Api\RefundOrderInterface::class)->execute($o, [$refundItem]);
$h->check('partial refund of unshipped qty on an open order releases nothing yet', $r()['qty_released'] === '0.0000' && $r()['status'] === 'open');
$refundItem = $om->create(\Magento\Sales\Api\Data\CreditmemoItemCreationInterface::class)->setOrderItemId($item)->setQty(2);
$om->get(\Magento\Sales\Api\RefundOrderInterface::class)->execute($o, [$refundItem]);
$state = $db->fetchOne('SELECT state FROM sales_order WHERE entity_id = ?', [$o]);
$h->check('full refund closes the order and releases the remainder', $state === 'closed' && $r()['qty_released'] === '3.0000' && $r()['status'] === 'closed', $state);

$q = $h->quote([['sku' => $sku, 'qty' => 2]]);
$o = $h->placeQuote($q);
$item = $h->orderItemId($o, $sku);
$om->get(\Magento\Sales\Api\InvoiceOrderInterface::class)->execute($o, true);
$ship($o, [$item => 2]);
$state = $db->fetchOne('SELECT state FROM sales_order WHERE entity_id = ?', [$o]);
$h->check('invoiced and fully shipped: complete, fulfilled 2, closed', $state === 'complete' && $r()['qty_fulfilled'] === '2.0000' && $r()['status'] === 'closed', $state);
$refundItem = $om->create(\Magento\Sales\Api\Data\CreditmemoItemCreationInterface::class)->setOrderItemId($item)->setQty(2);
$om->get(\Magento\Sales\Api\RefundOrderInterface::class)->execute($o, [$refundItem]);
$h->check('refund after shipment releases nothing (returns come back via snapshots)', $r()['qty_released'] === '0.0000');

$h->section('Snapshots and fulfilment (Examples 4 and 6)');
$h->snapshot($snapSku, '25');
$open = (float)$inv($snapSku)['reserved_qty'];
$pendingBefore = (float)$inv($snapSku)['fulfilled_pending_qty'];
$q = $h->quote([['sku' => $snapSku, 'qty' => 5]]);
$o = $h->placeQuote($q);
$ship($o, [$h->orderItemId($o, $snapSku) => 2]);
$h->check('ship 2: pending grows by 2', (float)$inv($snapSku)['fulfilled_pending_qty'] === $pendingBefore + 2);
$h->snapshot($snapSku, '8');
$h->check('Example 4: snapshot accepted after the shipment clears pending', (float)$inv($snapSku)['fulfilled_pending_qty'] === 0.0 && (float)$inv($snapSku)['quantity'] === 8.0);

$uLate = $h->accept($snapSku, '10');
$q = $h->quote([['sku' => $snapSku, 'qty' => 3]]);
$o = $h->placeQuote($q);
$ship($o, [$h->orderItemId($o, $snapSku) => 3]);
$om->get(\Company\SupplierSync\Model\Queue\UpdateConsumer::class)->process($uLate);
$h->check('Example 6: snapshot accepted before the shipment keeps it pending', (float)$inv($snapSku)['fulfilled_pending_qty'] === 3.0 && (float)$inv($snapSku)['quantity'] === 10.0, $counters($snapSku));

AdjustableConfig::$maxLag = 300;
$h->snapshot($snapSku, '10');
$h->check('lag window: every shipment of the last 300 s stays pending for a later snapshot (2 + 3)', (float)$inv($snapSku)['fulfilled_pending_qty'] === 5.0, $counters($snapSku));
AdjustableConfig::$maxLag = 0;
$h->snapshot($snapSku, '10');
$h->check('outside the lag window it clears', (float)$inv($snapSku)['fulfilled_pending_qty'] === 0.0);

$h->section('Snapshot below reservations');
$reservedNow = (float)$inv($snapSku)['reserved_qty'];
$h->snapshot($snapSku, (string)max(0, $reservedNow - 2));
$view = $om->get(\Company\SupplierSync\Api\InventoryQueryInterface::class)->get($snapSku, 'base');
$view = array_values(array_filter($view, fn ($v) => $v->getSupplierCode() === 'scenario-place'))[0];
$h->check('reservations preserved, shortage exposed, available 0', (float)$view->getReservedQty() === $reservedNow && (float)$view->getShortage() === 2.0 && (float)$view->getAvailable() === 0.0);
$q = $h->quote([['sku' => $snapSku, 'qty' => 1]]);
$h->check('new reservations are blocked', $try(fn () => $h->placeQuote($q)) !== null);

$h->section('Supplier reassignment and disabled suppliers');
$h->snapshot($sku, '500');
$q = $h->quote([['sku' => $sku, 'qty' => 2]]);
$o = $h->placeQuote($q);
$om->get(\Company\SupplierSync\Api\AssignmentManagementInterface::class)->assign($sku, 'base', 'acme');
$om->get(\Magento\Sales\Api\OrderManagementInterface::class)->cancel($o);
$h->check('after reassignment, the old order releases on the original supplier', $r()['status'] === 'closed' && (int)$h->reservations(['order_id = ?' => $o])[0]['inventory_id'] === (int)$inv()['inventory_id']);
$h->supplier($sku);
$q = $h->quote([['sku' => $sku, 'qty' => 2]]);
$o = $h->placeQuote($q);
$suppliers = $om->get(\Company\SupplierSync\Api\SupplierRepositoryInterface::class);
$suppliers->save($suppliers->getByCode('scenario-place')->setIsActive(false));
$q2 = $h->quote([['sku' => $sku, 'qty' => 1]]);
$h->check('disabled supplier: new reservations refused', $try(fn () => $h->placeQuote($q2)) !== null);
$om->get(\Magento\Sales\Api\OrderManagementInterface::class)->cancel($o);
$h->check('disabled supplier: releases still happen', $r()['status'] === 'closed' && $r()['qty_released'] === '2.0000');
$h->supplier();

$h->section('Event ids follow commit order per inventory row');
$events = $db->fetchAll('SELECT event_id, created_at FROM company_supplier_reservation_event WHERE inventory_id = ? ORDER BY event_id', [(int)$inv()['inventory_id']]);
$sorted = $events;
usort($sorted, fn ($x, $y) => [$x['created_at'], $x['event_id']] <=> [$y['created_at'], $y['event_id']]);
$h->check('event_id order equals timestamp order', array_column($events, 'event_id') === array_column($sorted, 'event_id'));

$h->cleanup();
exit($h->finish());
