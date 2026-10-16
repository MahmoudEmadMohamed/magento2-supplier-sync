<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Test\Unit\Model\Placement;

use Company\SupplierSync\Model\AttemptStatus;
use Company\SupplierSync\Model\Db\TransactionRunner;
use Company\SupplierSync\Model\Placement\AttemptResolver;
use Company\SupplierSync\Model\Placement\AttemptStore;
use Company\SupplierSync\Model\Reservation\ReservationLedger;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * AttemptResolver must lock the attempt row before any ledger or status change (invariant I3).
 */
class AttemptResolverTest extends TestCase
{
    /** @var string[] */
    private array $calls = [];

    /**
     * Build a resolver whose collaborators record the order in which they are called.
     *
     * @param array<string,mixed> $attempt Row returned by AttemptStore::lock
     * @param array<int,array<string,mixed>> $orderItems Rows returned for sales_order_item
     * @return AttemptResolver
     */
    private function resolver(array $attempt, array $orderItems = []): AttemptResolver
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('1'); // lease expired
        $connection->method('fetchAll')->willReturn($orderItems);

        $runner = $this->createStub(TransactionRunner::class);
        $runner->method('run')->willReturnCallback(fn (callable $work) => $work($connection));

        $store = $this->createStub(AttemptStore::class);
        $store->method('lock')->willReturnCallback(function () use ($attempt) {
            $this->calls[] = 'lock';
            return $attempt;
        });
        $store->method('transition')->willReturnCallback(function ($c, $a, string $to) {
            $this->calls[] = 'transition:' . $to;
        });
        $store->method('markDetached')->willReturnCallback(function () {
            $this->calls[] = 'markDetached';
        });

        $ledger = $this->createStub(ReservationLedger::class);
        foreach (['releaseAttempt', 'detachAttempt', 'linkAttempt'] as $method) {
            $ledger->method($method)->willReturnCallback(function () use ($method) {
                $this->calls[] = $method;
            });
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getTableName')->willReturnArgument(0);

        return new AttemptResolver($runner, $store, $ledger, $resource, new NullLogger());
    }

    /**
     * An attempt row.
     *
     * @param string $status
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function attempt(string $status, array $extra = []): array
    {
        return $extra + [
            'attempt_id' => 7, 'quote_id' => 3, 'status' => $status, 'order_id' => null,
            'lease_until' => '2026-01-01 00:00:00', 'detached_at' => null,
        ];
    }

    /**
     * Fail locks, then releases, then changes status.
     *
     * @return void
     */
    public function testFailLocksBeforeReleasing(): void
    {
        $this->assertTrue($this->resolver($this->attempt(AttemptStatus::PAYING))->fail(7, 'test'));
        $this->assertSame(['lock', 'releaseAttempt', 'transition:failed'], $this->calls);
    }

    /**
     * An attempt whose order exists is never released.
     *
     * @return void
     */
    public function testFailRefusesSavedOrders(): void
    {
        $resolver = $this->resolver($this->attempt(AttemptStatus::ORDER_SAVED, ['order_id' => 5]));
        $this->assertFalse($resolver->fail(7, 'test'));
        $this->assertSame(['lock'], $this->calls);
    }

    /**
     * Recovery of an expired started attempt releases it.
     *
     * @return void
     */
    public function testRecoverStartedReleases(): void
    {
        $this->assertSame('released', $this->resolver($this->attempt(AttemptStatus::STARTED))->recover(7));
        $this->assertSame(['lock', 'releaseAttempt', 'transition:failed'], $this->calls);
    }

    /**
     * Recovery of an expired paying attempt keeps the hold and detaches it.
     *
     * @return void
     */
    public function testRecoverPayingDetaches(): void
    {
        $this->assertSame('detached', $this->resolver($this->attempt(AttemptStatus::PAYING))->recover(7));
        $this->assertSame(['lock', 'detachAttempt', 'markDetached'], $this->calls);
    }

    /**
     * Recovery of an order_saved attempt links it, mapping configurable children to their parent's quantities.
     *
     * @return void
     */
    public function testRecoverOrderSavedLinks(): void
    {
        $items = [
            ['item_id' => 10, 'quote_item_id' => 100, 'parent_item_id' => null],
            ['item_id' => 11, 'quote_item_id' => 101, 'parent_item_id' => 12],
        ];
        $resolver = $this->resolver($this->attempt(AttemptStatus::ORDER_SAVED, ['order_id' => 5]), $items);
        $this->assertSame('linked', $resolver->recover(7));
        $this->assertSame(['lock', 'linkAttempt', 'transition:completed'], $this->calls);
    }

    /**
     * Operator release applies only to detached paying attempts.
     *
     * @return void
     */
    public function testOperatorReleaseRequiresDetachedPaying(): void
    {
        $this->assertFalse($this->resolver($this->attempt(AttemptStatus::PAYING))->operatorRelease(7));
        $this->assertSame(['lock'], $this->calls);
        $this->calls = [];
        $detached = $this->attempt(AttemptStatus::PAYING, ['detached_at' => '2026-01-01 00:00:00']);
        $this->assertTrue($this->resolver($detached)->operatorRelease(7));
        $this->assertSame(['lock', 'releaseAttempt', 'transition:failed'], $this->calls);
    }

    /**
     * After proceed() threw: link if the order exists, otherwise fail.
     *
     * @return void
     */
    public function testResolveAfterException(): void
    {
        $saved = $this->attempt(AttemptStatus::ORDER_SAVED, ['order_id' => 5]);
        $this->assertSame(5, $this->resolver($saved)->resolveAfterException(7));
        $this->assertSame(['lock', 'linkAttempt', 'transition:completed'], $this->calls);
        $this->calls = [];
        $this->assertNull($this->resolver($this->attempt(AttemptStatus::PAYING))->resolveAfterException(7));
        $this->assertSame(['lock', 'releaseAttempt', 'transition:failed'], $this->calls);
    }
}
