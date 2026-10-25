<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic in integer minor units (cents), with no floats and
 * no extension dependency. Accepts only plain decimal strings: digits, an
 * optional "." and at most two decimals — no signs, exponents, separators or
 * whitespace.
 */
final class Money
{
    private const PATTERN = '/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/D';

    public static function isValid(string $amount): bool
    {
        return preg_match(self::PATTERN, $amount) === 1;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function toMinor(string $amount): int
    {
        if (preg_match(self::PATTERN, $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Not a valid money amount.');
        }

        return ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public static function fromMinor(int $minor): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('Money amounts cannot be negative.');
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Normalises a valid amount to exactly two decimals ("1500" → "1500.00").
     */
    public static function format(string $amount): string
    {
        return self::fromMinor(self::toMinor($amount));
    }
}
