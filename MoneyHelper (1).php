<?php

declare(strict_types=1);

namespace App\Helpers;

use InvalidArgumentException;

/**
 * MoneyHelper
 *
 * ALL financial calculations must go through this class.
 *
 * Why: PHP floats are IEEE 754 binary floats.
 *   0.1 + 0.2 = 0.30000000000000004  ← WRONG
 *   This causes wallet desynchronization in high-volume systems.
 *
 * Solution: Store and calculate in KOBO (integer, 1 NGN = 100 kobo)
 *   Then convert to NGN only for display.
 *
 * Usage:
 *   Money::toKobo(100.50)           → 10050  (store this in DB)
 *   Money::toNaira(10050)           → 100.50 (display this)
 *   Money::add(10050, 5000)         → 15050  (safe integer math)
 *   Money::subtract(10050, 5000)    → 5050
 *   Money::multiply(10050, 0.03)    → 301    (3% of 100.50)
 *   Money::format(10050)            → "₦100.50"
 *
 * NOTE: Our DB stores decimal(12,2) for readability.
 * We use BCMath for all calculations, then store as decimal.
 * This prevents float arithmetic errors without changing DB schema.
 */
class MoneyHelper
{
    private const SCALE = 2; // decimal places

    /**
     * Add two money amounts safely.
     * Input and output in Naira (e.g. 100.50).
     */
    public static function add(string|float $a, string|float $b): string
    {
        return bcadd(
            (string) $a,
            (string) $b,
            self::SCALE,
        );
    }

    /**
     * Subtract two money amounts safely.
     *
     * @throws InvalidArgumentException if result would be negative
     */
    public static function subtract(
        string|float $a,
        string|float $b,
        bool $allowNegative = false,
    ): string {
        $result = bcsub((string) $a, (string) $b, self::SCALE);

        if (! $allowNegative && bccomp($result, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException(
                "Subtraction would result in negative amount: {$a} - {$b}"
            );
        }

        return $result;
    }

    /**
     * Multiply a money amount by a factor (e.g. percentage).
     * Example: multiply(1000, 0.03) → 30.00 (3% of 1000)
     */
    public static function multiply(string|float $amount, string|float $factor): string
    {
        return bcmul(
            (string) $amount,
            (string) $factor,
            self::SCALE,
        );
    }

    /**
     * Divide a money amount.
     */
    public static function divide(string|float $amount, string|float $divisor): string
    {
        if (bccomp((string) $divisor, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('Cannot divide by zero.');
        }

        return bcdiv(
            (string) $amount,
            (string) $divisor,
            self::SCALE,
        );
    }

    /**
     * Compare two amounts.
     * Returns: -1 (a < b), 0 (a == b), 1 (a > b)
     */
    public static function compare(string|float $a, string|float $b): int
    {
        return bccomp((string) $a, (string) $b, self::SCALE);
    }

    /**
     * Check if a >= b (sufficient balance check).
     */
    public static function greaterThanOrEqual(string|float $a, string|float $b): bool
    {
        return self::compare($a, $b) >= 0;
    }

    /**
     * Calculate percentage of an amount.
     * Example: percentage(1000, 2.5) → 25.00 (2.5% of 1000)
     */
    public static function percentage(string|float $amount, string|float $percent): string
    {
        return self::multiply(
            $amount,
            self::divide($percent, '100'),
        );
    }

    /**
     * Calculate discount price.
     * Example: applyDiscount(1000, 98) → 980.00 (98% of 1000)
     */
    public static function applyDiscount(string|float $amount, string|float $discountPercent): string
    {
        return self::multiply(
            $amount,
            self::divide($discountPercent, '100'),
        );
    }

    /**
     * Calculate profit on a transaction.
     * Example: profit(1000, 970) → 30.00
     */
    public static function profit(string|float $sellingPrice, string|float $costPrice): string
    {
        return self::subtract($sellingPrice, $costPrice, allowNegative: true);
    }

    /**
     * Format for display.
     * Example: format(1000.50) → "₦1,000.50"
     */
    public static function format(string|float $amount, string $symbol = '₦'): string
    {
        return $symbol . number_format((float) $amount, 2, '.', ',');
    }

    /**
     * Ensure an amount is a valid positive money value.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string|float $amount, string $field = 'amount'): string
    {
        $amount = (string) $amount;

        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("{$field} must be numeric.");
        }

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException("{$field} must be greater than zero.");
        }

        return $amount;
    }

    /**
     * Round to 2 decimal places (for display only, not calculation).
     */
    public static function round(string|float $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
