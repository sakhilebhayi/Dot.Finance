<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money in Dot.Finance is a signed integer count of minor units (cents)
 * in one currency. Nothing here, and nothing that calls it, does arithmetic
 * on floats: a decimal string goes in, an integer comes out.
 *
 * Every supported currency is treated as having two minor-unit digits. That
 * is true of ZAR, USD, EUR, GBP and the other currencies this app is used
 * with; a zero- or three-decimal currency (JPY, KWD) would need a per-currency
 * exponent and is deliberately out of scope rather than silently wrong.
 */
final class Money
{
    /** Largest magnitude accepted from a form: 999,999,999,999.99. */
    public const MAX_CENTS = 99_999_999_999_999;

    /**
     * Parse a plain decimal string ("1234", "1234.5", "-0.05") into cents.
     * Exactly two decimal places at most; no exponents, no grouping, no
     * whitespace. Use parseLocalized() for bank-style input.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $value): int
    {
        if (preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException("Not a valid amount: {$value}");
        }

        $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        try {
            self::parse((string) $value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Convert whatever a legacy caller hands over (a DB decimal, an int or
     * float from older code and tests) into cents. A float is rounded to the
     * nearest cent via number_format, which is how a decimal(15,2) column
     * round-trips; strings go through the strict parser.
     */
    public static function fromDecimal(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_string($value)) {
            return self::parse($value);
        }

        return self::parse(number_format((float) $value, 2, '.', ''));
    }

    /** 123456 -> "1234.56", -5 -> "-0.05". */
    public static function toDecimalString(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /** 123456, 'ZAR' -> "ZAR 1,234.56"; negatives lead with a minus sign. */
    public static function format(int $cents, ?string $currency = null, bool $showPlus = false): string
    {
        $abs = abs($cents);
        $number = number_format(intdiv($abs, 100), 0, '.', ',').sprintf('.%02d', $abs % 100);
        $sign = $cents < 0 ? '-' : ($showPlus && $cents > 0 ? '+' : '');

        return $sign.($currency ? $currency.' ' : '').$number;
    }

    /** Integer division rounded half away from zero, so no float ever touches an amount. */
    public static function divideRound(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        $sign = (($numerator < 0) xor ($denominator < 0)) ? -1 : 1;
        $n = abs($numerator);
        $d = abs($denominator);

        return $sign * intdiv(2 * $n + $d, 2 * $d);
    }

    /** The magnitude only, e.g. for a table cell whose column header names the currency. */
    public static function formatPlain(int $cents): string
    {
        return self::format($cents);
    }
}
