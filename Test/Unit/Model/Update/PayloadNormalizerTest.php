<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Test\Unit\Model\Update;

use Company\SupplierSync\Exception\ApiException;
use Company\SupplierSync\Model\Update\PayloadNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PayloadNormalizerTest extends TestCase
{
    /**
     * Normalize with defaults, overriding selected fields.
     *
     * @param array<string,string> $fields
     * @return \Company\SupplierSync\Model\Update\NormalizedPayload
     */
    private function normalize(array $fields = []): \Company\SupplierSync\Model\Update\NormalizedPayload
    {
        $f = $fields + [
            'supplier' => 'acme', 'key' => 'k-1', 'sku' => 'SKU-1', 'qty' => '25',
            'price' => '49.99', 'currency' => 'USD', 'website' => 'base',
        ];
        return (new PayloadNormalizer())->normalize(
            $f['supplier'],
            $f['key'],
            $f['sku'],
            $f['qty'],
            $f['price'],
            $f['currency'],
            $f['website']
        );
    }

    /**
     * Equivalent representations normalize to the same values and hash.
     *
     * @param array<string,string> $variant
     * @return void
     */
    #[DataProvider('equivalentProvider')]
    public function testEquivalentPayloadsHashTheSame(array $variant): void
    {
        $this->assertSame($this->normalize()->hash, $this->normalize($variant)->hash);
    }

    /**
     * Representations of the same payload.
     *
     * @return array<string, array{array<string,string>}>
     */
    public static function equivalentProvider(): array
    {
        return [
            'trailing zeros in price' => [['price' => '49.990']],
            'trailing zeros in quantity' => [['qty' => '25.0000']],
            'lower-case currency' => [['currency' => 'usd']],
            'upper-case supplier code' => [['supplier' => 'ACME']],
            'surrounding whitespace' => [['sku' => ' SKU-1 ', 'website' => ' base ', 'currency' => ' USD ']],
            'different idempotency key (not part of the payload)' => [['key' => 'k-2']],
        ];
    }

    /**
     * Any change to the business payload changes the hash.
     *
     * @param array<string,string> $variant
     * @return void
     */
    #[DataProvider('differentProvider')]
    public function testDifferentPayloadsHashDifferently(array $variant): void
    {
        $this->assertNotSame($this->normalize()->hash, $this->normalize($variant)->hash);
    }

    /**
     * Payload changes.
     *
     * @return array<string, array{array<string,string>}>
     */
    public static function differentProvider(): array
    {
        return [
            'quantity' => [['qty' => '26']],
            'price' => [['price' => '49.98']],
            'currency' => [['currency' => 'EUR']],
            'sku' => [['sku' => 'SKU-2']],
            'website' => [['website' => 'other']],
            'supplier' => [['supplier' => 'globex']],
        ];
    }

    /**
     * Normalized values are fixed-scale strings and an upper-case currency.
     *
     * @return void
     */
    public function testNormalizedValues(): void
    {
        $p = $this->normalize(['qty' => '1.5', 'price' => '0.1', 'currency' => 'eur']);
        $this->assertSame('1.5000', $p->quantity);
        $this->assertSame('0.100000', $p->unitPrice);
        $this->assertSame('EUR', $p->currency);
        $this->assertSame(64, strlen($p->hash));
    }

    /**
     * Malformed fields are rejected with their error code.
     *
     * @param array<string,string> $variant
     * @param string $errorCode
     * @return void
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidFields(array $variant, string $errorCode): void
    {
        try {
            $this->normalize($variant);
            $this->fail('Expected ApiException ' . $errorCode);
        } catch (ApiException $e) {
            $this->assertSame($errorCode, $e->getErrorCode());
            $this->assertSame(400, $e->getHttpCode());
            $this->assertSame($errorCode, $e->getDetails()['error_code']);
        }
    }

    /**
     * Invalid inputs and their codes.
     *
     * @return array<string, array{array<string,string>, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'empty key' => [['key' => ''], 'invalid_idempotency_key'],
            'key with space' => [['key' => 'a b'], 'invalid_idempotency_key'],
            'key too long' => [['key' => str_repeat('k', 129)], 'invalid_idempotency_key'],
            'empty sku' => [['sku' => ' '], 'invalid_sku'],
            'sku too long' => [['sku' => str_repeat('S', 65)], 'invalid_sku'],
            'negative quantity' => [['qty' => '-1'], 'invalid_qty'],
            'quantity scale 5' => [['qty' => '1.00001'], 'invalid_qty'],
            'quantity exponent' => [['qty' => '1e3'], 'invalid_qty'],
            'quantity too large' => [['qty' => '100000000'], 'invalid_qty'],
            'negative price' => [['price' => '-0.01'], 'invalid_price'],
            'price scale 7' => [['price' => '1.0000001'], 'invalid_price'],
            'currency two letters' => [['currency' => 'US'], 'invalid_currency'],
            'currency digits' => [['currency' => '840'], 'invalid_currency'],
        ];
    }
}
