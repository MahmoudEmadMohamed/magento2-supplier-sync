<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Test\Unit\Model;

use Company\SupplierSync\Model\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    /**
     * Equivalent inputs normalize to the same string, so they hash the same.
     *
     * @param string $input
     * @param int $scale
     * @param string $expected
     * @return void
     */
    #[DataProvider('validProvider')]
    public function testNormalizeAcceptsPlainDecimals(string $input, int $scale, string $expected): void
    {
        $this->assertSame($expected, Decimal::normalize($input, $scale));
    }

    /**
     * Valid inputs.
     *
     * @return array<string, array{string, int, string}>
     */
    public static function validProvider(): array
    {
        return [
            'integer' => ['10', 4, '10.0000'],
            'zero' => ['0', 4, '0.0000'],
            'price' => ['49.99', 6, '49.990000'],
            'trailing zeros' => ['49.990', 6, '49.990000'],
            'trailing zeros beyond scale' => ['1.50000000', 4, '1.5000'],
            'max scale' => ['0.0001', 4, '0.0001'],
        ];
    }

    /**
     * Signs, exponents, whitespace, empty strings and excess scale are rejected.
     *
     * @param string $input
     * @return void
     */
    #[DataProvider('invalidProvider')]
    public function testNormalizeRejectsInvalidInput(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::normalize($input, 4);
    }

    /**
     * Invalid inputs.
     *
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'negative' => ['-1'],
            'plus sign' => ['+1'],
            'exponent' => ['1e3'],
            'empty' => [''],
            'space' => [' 1'],
            'leading zero' => ['01'],
            'bare point' => ['1.'],
            'too many decimals' => ['0.00001'],
            'comma' => ['1,5'],
            // phpcs:ignore PHPCompatibility.Numbers.RemovedHexadecimalNumericStrings -- deliberate invalid input
            'hex' => ['0x10'],
        ];
    }

    /**
     * Values wider than the column are rejected.
     *
     * @return void
     */
    public function testNormalizeRejectsValuesWiderThanTheColumn(): void
    {
        $this->assertSame('99999999.0000', Decimal::normalize('99999999', 4, 8));
        $this->expectException(InvalidArgumentException::class);
        Decimal::normalize('100000000', 4, 8);
    }

    /**
     * Arithmetic stays exact where floats would drift.
     *
     * @return void
     */
    public function testArithmeticIsExact(): void
    {
        $this->assertSame('0.3000', Decimal::add('0.1', '0.2'));
        $this->assertSame('-0.5000', Decimal::sub('1', '1.5'));
        $this->assertSame('0.0000', Decimal::clampZero('-0.5'));
        $this->assertSame('2.5000', Decimal::clampZero('2.5'));
        $this->assertSame('1.0000', Decimal::min('1', '2'));
        $this->assertSame(0, Decimal::cmp('1.0000', '1'));
        $this->assertTrue(Decimal::isPositive('0.0001'));
        $this->assertFalse(Decimal::isPositive('0.0000'));
        $this->assertTrue(Decimal::hasFraction('1.5000'));
        $this->assertFalse(Decimal::hasFraction('2.0000'));
    }
}
