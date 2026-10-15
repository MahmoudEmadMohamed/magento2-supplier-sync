<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Test\Unit\Model;

use Company\SupplierSync\Model\ReservationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReservationStatusTest extends TestCase
{
    /**
     * Status is derived from the counters only.
     *
     * @param string $reserved
     * @param string $fulfilled
     * @param string $released
     * @param bool $linked
     * @param string $expected
     * @return void
     */
    #[DataProvider('cases')]
    public function testDerive(
        string $reserved,
        string $fulfilled,
        string $released,
        bool $linked,
        string $expected
    ): void {
        $this->assertSame($expected, ReservationStatus::derive($reserved, $fulfilled, $released, $linked));
    }

    /**
     * Mixed outcomes close when nothing is left open.
     *
     * @return array<string,array{string,string,string,bool,string}>
     */
    public static function cases(): array
    {
        return [
            'held, not linked' => ['2', '0', '0', false, ReservationStatus::PENDING_PLACEMENT],
            'linked' => ['2', '0', '0', true, ReservationStatus::OPEN],
            'partially shipped' => ['3', '1', '0', true, ReservationStatus::OPEN],
            'partially cancelled' => ['3', '0', '1', true, ReservationStatus::OPEN],
            'fully shipped' => ['3', '3', '0', true, ReservationStatus::CLOSED],
            'fully released before linking' => ['3', '0', '3', false, ReservationStatus::CLOSED],
            'ship plus cancel' => ['3', '1', '2', true, ReservationStatus::CLOSED],
            'decimal quantities' => ['1.5', '0.5', '1', true, ReservationStatus::CLOSED],
        ];
    }
}
