<?php
/**
 * Copyright © Company. All rights reserved.
 *
 * Queue and intake failure scenarios, verified from persisted rows.
 *
 * Usage: php Test/Scenario/queue-scenarios.php [magento_root]
 */
declare(strict_types=1);

// phpcs:ignoreFile -- standalone CLI test harness, not module runtime code.

use Company\SupplierSync\Model\Queue\OutboxDispatcher;
use Company\SupplierSync\Model\Queue\UpdateConsumer;
use Company\SupplierSync\Model\Queue\UpdateSweeper;
use Company\SupplierSync\Model\Update\UpdateRecovery;
use Company\SupplierSync\Test\Scenario\Harness;

require __DIR__ . '/Harness.php';
$h = new Harness(realpath($argv[1] ?? __DIR__ . '/../../../../../..'), 'scenario-queue');
$db = $h->db;
$consumer = $h->om->get(UpdateConsumer::class);
$sweeper = $h->om->get(UpdateSweeper::class);
$dispatcher = $h->om->get(OutboxDispatcher::class);
$recovery = $h->om->get(UpdateRecovery::class);
$sku = 'SUPPLIER-SYNC-Q';
$h->product($sku);
$h->product('SUPPLIER-SYNC-Q-NEW');
$h->cleanup();
$h->supplier($sku, 'SUPPLIER-SYNC-Q-NEW');

/** Put an update into processing as if a worker had claimed it. */
$claimAs = function (int $id, string $token, bool $leaseValid, int $attempts = 1) use ($db): void {
    $db->query(
        "UPDATE company_supplier_update SET status = 'processing', claim_token = ?, attempts = ?,"
        . ' lease_until = NOW() + INTERVAL ? SECOND WHERE update_id = ?',
        [$token, $attempts, $leaseValid ? 120 : -1, $id]
    );
};
$makeDue = fn (int $id) => $db->query(
    'UPDATE company_supplier_update SET next_attempt_at = NOW() - INTERVAL 1 SECOND WHERE update_id = ?',
    [$id]
);
$secondConnection = function () use ($h): PDO {
    $c = (include $h->root . '/app/etc/env.php')['db']['connection']['default'];
    return new PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['username'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
};

$h->section('Apply and ordering');
$u1 = $h->accept($sku, '10');
$u2 = $h->accept($sku, '20');
$consumer->process($u2);
$consumer->process($u1);
$h->check('newer update applied first', $h->update($u2)['status'] === 'applied');
$h->check('older update processed later is skipped_stale', $h->update($u1)['status'] === 'skipped_stale');
$h->check('quantity is the newer snapshot (20)', $h->inventory($sku)['quantity'] === '20.0000');
$h->check('last_update_id is the newer id', (int)$h->inventory($sku)['last_update_id'] === $u2);
$events = (int)$db->fetchOne('SELECT COUNT(*) FROM company_supplier_update_event WHERE update_id = ?', [$u1]);
$h->check('audit trail: accepted, processing, skipped_stale', $events === 3);

$h->section('Duplicate deliveries');
$consumer->process($u2);
$h->check('terminal update: duplicate is a no-op, attempts unchanged', (int)$h->update($u2)['attempts'] === 1);
$u3 = $h->accept($sku, '30');
$claimAs($u3, 'worker-a', true);
$consumer->process($u3);
$row = $h->update($u3);
$h->check('valid claim: duplicate is a no-op', $row['status'] === 'processing' && $row['claim_token'] === 'worker-a');
$h->check('valid claim: duplicate spends no retry budget', (int)$row['attempts'] === 1);

$h->section('Crash after claim (sweep)');
$claimAs($u3, 'worker-a', false);
$swept = $sweeper->sweep();
$row = $h->update($u3);
$h->check('expired claim moved to retry_pending', $row['status'] === 'retry_pending', $row['status']);
$h->check('the crashed attempt stays counted (attempts = 1)', (int)$row['attempts'] === 1);
$h->check('claim token cleared', $row['claim_token'] === null);
$outbox = $h->outbox($u3);
$h->check('outbox rearmed not before next_attempt_at', $outbox['published_at'] === null && $outbox['next_publish_at'] === $row['next_attempt_at']);

$h->section('Takeover: a paused worker resumes after the sweep took its claim');
$apply = new ReflectionMethod($consumer, 'apply');
$apply->invoke($consumer, $u3, 'worker-a');
$h->check('stale worker made no change (still retry_pending)', $h->update($u3)['status'] === 'retry_pending');
$h->check('stale worker did not touch stock (still 20)', $h->inventory($sku)['quantity'] === '20.0000');

$h->section('Retry eligibility');
$consumer->process($u3);
$h->check('early duplicate during backoff is a no-op', $h->update($u3)['status'] === 'retry_pending' && (int)$h->update($u3)['attempts'] === 1);
$dispatcher->dispatch();
$h->check('dispatcher does not publish before next_attempt_at', $h->outbox($u3)['published_at'] === null);
$makeDue($u3);
$db->query('UPDATE company_supplier_outbox SET next_publish_at = NOW() - INTERVAL 1 SECOND WHERE update_id = ?', [$u3]);
$dispatcher->dispatch();
$h->check('dispatcher publishes once due', $h->outbox($u3)['published_at'] !== null);
$consumer->process($u3);
$row = $h->update($u3);
$h->check('due retry is claimed and applied (attempts = 2)', $row['status'] === 'applied' && (int)$row['attempts'] === 2);

$h->section('Sweep never steals a row locked by a working transaction');
$u4 = $h->accept($sku, '40');
$claimAs($u4, 'worker-b', false);
$other = $secondConnection();
$other->beginTransaction();
$other->query("SELECT * FROM company_supplier_update WHERE update_id = $u4 FOR UPDATE");
$swept = $sweeper->sweep();
$h->check('locked row skipped, not written', $h->update($u4)['status'] === 'processing' && $swept['skipped'] >= 1);
$other->rollBack();
$sweeper->sweep();
$h->check('after the lock is released, the sweep recovers it', $h->update($u4)['status'] === 'retry_pending');

$h->section('Poison message: retryable failure on every attempt');
$u5 = $h->accept($sku, '50');
$inventoryId = (int)$h->inventory($sku)['inventory_id'];
$blocker = $secondConnection();
$blocker->beginTransaction();
$blocker->query("SELECT * FROM company_supplier_inventory WHERE inventory_id = $inventoryId FOR UPDATE");
$db->query('SET SESSION innodb_lock_wait_timeout = 1');
$statuses = [];
for ($i = 1; $i <= 5; $i++) {
    $makeDue($u5);
    $consumer->process($u5);
    $statuses[] = $h->update($u5)['status'];
}
$row = $h->update($u5);
$h->check('attempts 1-4 end in retry_pending', array_slice($statuses, 0, 4) === array_fill(0, 4, 'retry_pending'), implode(',', $statuses));
$h->check('attempt 5 ends in failed', $row['status'] === 'failed' && (int)$row['attempts'] === 5);
$h->check('failure classified as a lock wait timeout', $row['error_code'] === 'db_lock_wait_timeout', (string)$row['error_code']);
$makeDue($u5);
$consumer->process($u5);
$h->check('failed is terminal for deliveries', $h->update($u5)['status'] === 'failed' && (int)$h->update($u5)['attempts'] === 5);
$blocker->rollBack();
$db->query('SET SESSION innodb_lock_wait_timeout = 50');
$recovery->retry($u5, false);
$row = $h->update($u5);
$h->check('CLI retry requeues with a fresh budget', $row['status'] === 'queued' && (int)$row['attempts'] === 0 && $row['error_code'] === null);
$consumer->process($u5);
$h->check('retried update applies', $h->update($u5)['status'] === 'applied');

$h->section('Rejections after acceptance');
$u6 = $h->accept($sku, '60');
$h->om->get(\Company\SupplierSync\Api\AssignmentManagementInterface::class)->assign($sku, 'base', 'acme');
$consumer->process($u6);
$row = $h->update($u6);
$h->check('reassigned after accept: rejected assignment_mismatch', $row['status'] === 'rejected' && $row['error_code'] === 'assignment_mismatch');
$h->check('rejection does not change stock (still 50)', $h->inventory($sku)['quantity'] === '50.0000');
try {
    $recovery->retry($u6, false);
    $h->check('retry of rejected without --force refused', false);
} catch (\Magento\Framework\Exception\LocalizedException) {
    $h->check('retry of rejected without --force refused', true);
}
$h->supplier($sku);
$recovery->retry($u6, true);
$consumer->process($u6);
$h->check('--force retry after fixing the cause applies', $h->update($u6)['status'] === 'applied' && $h->inventory($sku)['quantity'] === '60.0000');

$h->section('Disabled supplier after acceptance');
$u7 = $h->accept($sku, '70');
$suppliers = $h->om->get(\Company\SupplierSync\Api\SupplierRepositoryInterface::class);
$suppliers->save($suppliers->getByCode('scenario-queue')->setIsActive(false));
$consumer->process($u7);
$h->check('already-accepted update finishes applying', $h->update($u7)['status'] === 'applied');
$h->supplier();

$h->section('Lost and unpublished messages');
$u8 = $h->accept($sku, '80');
$db->query('UPDATE company_supplier_outbox SET published_at = NOW() - INTERVAL 11 MINUTE, attempts = 1 WHERE update_id = ?', [$u8]);
$sweeper->sweep();
$h->check('queued update published 11 min ago is rearmed', $h->outbox($u8)['published_at'] === null);
$db->query('UPDATE company_supplier_outbox SET published_at = NOW() - INTERVAL 11 MINUTE, attempts = 2 WHERE update_id = ?', [$u8]);
$sweeper->sweep();
$h->check('second republish waits longer (30 min)', $h->outbox($u8)['published_at'] !== null);
$db->query("UPDATE company_supplier_outbox SET published_at = NULL, claim_token = 'dead', claimed_until = NOW() - INTERVAL 1 SECOND WHERE update_id = ?", [$u8]);
$dispatcher->dispatch();
$h->check('abandoned dispatcher claim is reclaimed and published', $h->outbox($u8)['published_at'] !== null && $h->outbox($u8)['claim_token'] === null);
$consumer->process($u8);
$sweeper->sweep();
$h->check('terminal update is never rearmed', $h->update($u8)['status'] === 'applied' && $h->outbox($u8)['published_at'] !== null);

$h->section('Concurrency');
$newSku = 'SUPPLIER-SYNC-Q-NEW';
$a = $h->accept($newSku, '5');
$b = $h->accept($newSku, '7');
$results = $h->parallel([['process', (string)$a], ['process', (string)$b]]);
$rows = (int)$db->fetchOne('SELECT COUNT(*) FROM company_supplier_inventory WHERE supplier_id = ? AND sku = ?', [$h->supplierId, $newSku]);
$h->check('concurrent first applies create exactly one inventory row', $rows === 1);
$h->check('the newer update wins (quantity 7)', $h->inventory($newSku)['quantity'] === '7.0000');
$h->check('both updates reached a terminal status', in_array($h->update($a)['status'], ['applied', 'skipped_stale'], true) && $h->update($b)['status'] === 'applied');

$c = $h->accept($sku, '90');
$results = $h->parallel(array_fill(0, 20, ['process', (string)$c]));
$row = $h->update($c);
$h->check('20 concurrent deliveries: applied once', $row['status'] === 'applied');
$h->check('20 concurrent deliveries: attempts = 1', (int)$row['attempts'] === 1);
$applied = (int)$db->fetchOne("SELECT COUNT(*) FROM company_supplier_update_event WHERE update_id = ? AND to_status = 'applied'", [$c]);
$h->check('20 concurrent deliveries: one applied audit event', $applied === 1);

$key = 'concurrent-' . uniqid();
$submit = ['submit', 'scenario-queue', $key, $sku, '11', '2.5', 'USD', 'base'];
$results = $h->parallel(array_fill(0, 10, $submit));
$ids = array_unique(array_map(fn ($r) => $r['result']['update_id'] ?? null, $results));
$fresh = count(array_filter($results, fn ($r) => ($r['result']['replayed'] ?? true) === false));
$stored = (int)$db->fetchOne('SELECT COUNT(*) FROM company_supplier_update WHERE supplier_id = ? AND idempotency_key = ?', [$h->supplierId, $key]);
$h->check('10 concurrent identical submits: all succeed', count(array_filter($results, fn ($r) => $r['ok'])) === 10, json_encode($results[0]));
$h->check('10 concurrent identical submits: one stored update', $stored === 1);
$h->check('10 concurrent identical submits: same update_id for all', count($ids) === 1);
$h->check('10 concurrent identical submits: exactly one replayed=false', $fresh === 1);

$h->cleanup();
exit($h->finish());
