<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

use InvalidArgumentException;

/**
 * Decimal-safe arithmetic on strings (bcmath). Quantities use scale 4, prices scale 6.
 */
class Decimal
{
    public const QTY_SCALE = 4;
    public const PRICE_SCALE = 6;

    private const PATTERN = '/^(0|[1-9]\d*)(\.\d+)?$/';

    /**
     * Validate a non-negative plain decimal string and return it at a fixed scale.
     *
     * Rejects signs, exponents, whitespace and more fractional digits than $maxScale.
     *
     * @param string $value
     * @param int $maxScale
     * @param int|null $integerDigits Maximum digits before the point (column precision minus scale)
     * @return string
     * @throws InvalidArgumentException
     */
    public static function normalize(string $value, int $maxScale, ?int $integerDigits = null): string
    {
        if (!preg_match(self::PATTERN, $value, $m)) {
            throw new InvalidArgumentException('not a non-negative decimal');
        }
        $fraction = isset($m[2]) ? rtrim(substr($m[2], 1), '0') : '';
        if (strlen($fraction) > $maxScale) {
            throw new InvalidArgumentException('too many decimal places');
        }
        if ($integerDigits !== null && strlen($m[1]) > $integerDigits) {
            throw new InvalidArgumentException('value too large');
        }
        return bcadd($value, '0', $maxScale);
    }

    /**
     * Convert a Magento quantity (DB string, int or float) to a quantity-scale string.
     *
     * Magento quantities have at most 4 decimals, so rounding a float to 4 places is exact for them.
     *
     * @param string|int|float|null $value
     * @return string
     */
    public static function fromNumber(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return bcadd('0', '0', self::QTY_SCALE);
        }
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return bcadd($value, '0', self::QTY_SCALE);
        }
        return sprintf('%.' . self::QTY_SCALE . 'F', (float)$value);
    }

    /**
     * Whether a normalized value has a non-zero fractional part.
     *
     * @param string $value
     * @return bool
     */
    public static function hasFraction(string $value): bool
    {
        return bccomp($value, bcadd($value, '0', 0), self::PRICE_SCALE) !== 0;
    }

    /**
     * Add.
     *
     * @param string $a
     * @param string $b
     * @return string
     */
    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::QTY_SCALE);
    }

    /**
     * Sub.
     *
     * @param string $a
     * @param string $b
     * @return string
     */
    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::QTY_SCALE);
    }

    /**
     * Compare at quantity scale: -1, 0 or 1.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::QTY_SCALE);
    }

    /**
     * Min.
     *
     * @param string $a
     * @param string $b
     * @return string
     */
    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? bcadd($a, '0', self::QTY_SCALE) : bcadd($b, '0', self::QTY_SCALE);
    }

    /**
     * Return max(0, $a).
     *
     * @param string $a
     * @return string
     */
    public static function clampZero(string $a): string
    {
        return self::cmp($a, '0') < 0 ? bcadd('0', '0', self::QTY_SCALE) : bcadd($a, '0', self::QTY_SCALE);
    }

    /**
     * Is positive.
     *
     * @param string $a
     * @return bool
     */
    public static function isPositive(string $a): bool
    {
        return self::cmp($a, '0') > 0;
    }
}
