<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Test\Unit\Model;

use Company\SupplierSync\Model\StateTransitionValidator as V;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StateTransitionValidatorTest extends TestCase
{
    /**
     * Every allowed transition, written out independently of the implementation.
     */
    private const ALLOWED = [
        V::UPDATE => [
            'queued' => ['processing'],
            'retry_pending' => ['processing'],
            'processing' => ['applied', 'skipped_stale', 'rejected', 'retry_pending', 'failed'],
            'failed' => ['queued'],
            'rejected' => ['queued'],
            'applied' => [],
            'skipped_stale' => [],
        ],
        V::ATTEMPT => [
            'started' => ['paying', 'failed'],
            'paying' => ['order_saved', 'failed'],
            'order_saved' => ['completed'],
            'completed' => [],
            'failed' => [],
        ],
        V::RESERVATION => [
            'pending_placement' => ['open', 'closed'],
            'open' => ['closed'],
            'closed' => [],
        ],
    ];

    /**
     * Check the full from × to matrix of each machine.
     *
     * @param string $machine
     * @param string $from
     * @param string $to
     * @param bool $allowed
     * @return void
     */
    #[DataProvider('matrixProvider')]
    public function testMatrix(string $machine, string $from, string $to, bool $allowed): void
    {
        $validator = new V();
        $this->assertSame($allowed, $validator->isAllowed($machine, $from, $to), "$machine: $from -> $to");
        if (!$allowed) {
            $this->expectException(LogicException::class);
        }
        $validator->assert($machine, $from, $to);
    }

    /**
     * Every pair of statuses of every machine.
     *
     * @return array<string,array{string,string,string,bool}>
     */
    public static function matrixProvider(): array
    {
        $cases = [];
        foreach (self::ALLOWED as $machine => $edges) {
            $statuses = array_keys($edges);
            foreach ($statuses as $from) {
                foreach ($statuses as $to) {
                    $allowed = $from === $to || in_array($to, $edges[$from], true);
                    $cases["$machine $from->$to"] = [$machine, $from, $to, $allowed];
                }
            }
        }
        return $cases;
    }

    /**
     * The implementation knows exactly the statuses listed here.
     *
     * @return void
     */
    public function testStatusesMatch(): void
    {
        $validator = new V();
        foreach (self::ALLOWED as $machine => $edges) {
            $this->assertEqualsCanonicalizing(array_keys($edges), $validator->getStatuses($machine));
        }
    }

    /**
     * Unknown statuses and machines are never allowed.
     *
     * @return void
     */
    public function testUnknownStatusIsRejected(): void
    {
        $validator = new V();
        $this->assertFalse($validator->isAllowed(V::UPDATE, 'bogus', 'queued'));
        $this->assertFalse($validator->isAllowed('bogus', 'queued', 'queued'));
    }
}
