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

    /**
     * Parse a bank-statement amount in the stated number format, into signed
     * cents, with no floats.
     *
     *   'en' : 1,234.56   comma groups thousands, dot is the decimal mark
     *   'eu' : 1 234,56   space (or non-breaking space) or dot groups thousands,
     *                     comma is the decimal mark; also 1.234,56
     *
     * A currency symbol or code before or after the number is ignored, a leading
     * "-" or "(...)" makes it negative. At most two decimal places; anything it
     * cannot read exactly is rejected rather than guessed.
     *
     * @throws InvalidArgumentException
     */
    public static function parseLocalized(string $raw, string $locale): int
    {
        if (! in_array($locale, ['en', 'eu'], true)) {
            throw new InvalidArgumentException("Unknown number format: {$locale}");
        }

        $value = trim($raw);
        $negative = false;

        if (preg_match('/^\((.*)\)$/u', $value, $m) === 1) {
            $negative = true;
            $value = trim($m[1]);
        }

        // currency symbols / codes / stray text around the number
        $value = preg_replace('/^[^\d+\-.,]+/u', '', $value);
        $value = preg_replace('/[^\d]+$/u', '', $value);
        $value = preg_replace('/^([+\-])\s+/u', '$1', $value);

        if (preg_match('/^([+\-]?)(.*)$/u', $value, $m) !== 1 || $m[2] === '') {
            throw new InvalidArgumentException("Not a valid amount: {$raw}");
        }

        if ($m[1] === '-') {
            $negative = ! $negative;
        }

        $body = $m[2];

        // spaces, NBSP and narrow NBSP only ever group thousands
        $body = preg_replace('/[\x{00A0}\x{202F}\x{2009} ]/u', '', $body);

        [$group, $decimal] = $locale === 'en' ? [',', '.'] : ['.', ','];
        // 'eu' also uses a space as the group mark; spaces are already stripped above.

        $pattern = '/^(?:\d{1,3}(?:'.preg_quote($group, '/').'\d{3})+|\d+)(?:'.preg_quote($decimal, '/').'(\d+))?$/';

        if (preg_match($pattern, $body, $parts) !== 1) {
            throw new InvalidArgumentException("Not a valid {$locale} amount: {$raw}");
        }

        $fraction = $parts[1] ?? '';
        if (strlen($fraction) > 2 && rtrim(substr($fraction, 2), '0') !== '') {
            throw new InvalidArgumentException("More than two decimal places: {$raw}");
        }

        $plain = str_replace($group, '', $body);
        $plain = $fraction === '' ? $plain : str_replace($decimal, '.', $plain);
        // drop zero padding beyond two places ("12.500" is 12.50)
        if ($fraction !== '') {
            $plain = substr($plain, 0, strpos($plain, '.') + 1).substr($fraction, 0, 2);
        }

        $cents = self::parse($plain);

        return $negative ? -$cents : $cents;
    }

    /**
     * Decide which number format a column of sample amounts is written in, or
     * report that it cannot be told. Never guesses: a lone "1,234" could be one
     * thousand two hundred and thirty-four (en) or 1.234 (eu).
     *
     * @param  list<string>  $samples
     * @return array{locale: ?string, ambiguous: bool}
     */
    public static function detectLocale(array $samples): array
    {
        $evidence = [];
        $unsure = false;

        foreach ($samples as $sample) {
            $value = preg_replace('/[\x{00A0}\x{202F}\x{2009} ]/u', '', preg_replace('/^[^\d.,]+|[^\d.,]+$/u', '', trim((string) $sample)));

            if ($value === '' || ! preg_match('/[.,]/', $value)) {
                continue; // no separator, nothing to disagree about
            }

            $dots = substr_count($value, '.');
            $commas = substr_count($value, ',');

            if ($dots > 0 && $commas > 0) {
                $evidence[strrpos($value, '.') > strrpos($value, ',') ? 'en' : 'eu'] = true;
            } elseif ($dots > 1) {
                $evidence['eu'] = true; // 1.234.567: dots group thousands
            } elseif ($commas > 1) {
                $evidence['en'] = true; // 1,234,567
            } else {
                $mark = $dots === 1 ? '.' : ',';
                $after = strlen(substr($value, strrpos($value, $mark) + 1));

                if ($after === 3) {
                    $unsure = true; // 1,234 or 1.234: either a thousands group or three decimals
                } else {
                    $evidence[$mark === '.' ? 'en' : 'eu'] = true;
                }
            }
        }

        if (count($evidence) > 1) {
            return ['locale' => null, 'ambiguous' => true];
        }

        if (count($evidence) === 1) {
            return ['locale' => array_key_first($evidence), 'ambiguous' => false];
        }

        return $unsure ? ['locale' => null, 'ambiguous' => true] : ['locale' => 'en', 'ambiguous' => false];
    }

    /** The magnitude only, e.g. for a table cell whose column header names the currency. */
    public static function formatPlain(int $cents): string
    {
        return self::format($cents);
    }
}
