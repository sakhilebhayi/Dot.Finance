<?php

namespace App\Services\Shared;

use App\Support\Money;
use App\Support\MoneyMath;
use InvalidArgumentException;

/**
 * Exchange-rate arithmetic as pure functions of integers: no database, no floats.
 *
 * A rate is an integer, rate_e8 = rate x 10^8 ("1 USD = 18.4649 ZAR" is 1_846_490_000). An amount
 * is in minor units of its currency, and the currencies may have different digits (JPY 0, ZAR 2, KWD 3),
 * so every conversion is told how many digits each side has. Rounding is half away from zero.
 */
final class FxMath
{
    public const SCALE = 100_000_000;

    /** Convert minor units of one currency into minor units of another. */
    public static function convert(int $amountMinor, int $rateE8, int $fromDigits, int $toDigits): int
    {
        self::assertRate($rateE8);

        // amount x (rate / 1e8) x 10^to / 10^from
        $up = $toDigits > $fromDigits ? 10 ** ($toDigits - $fromDigits) : 1;
        $down = $fromDigits > $toDigits ? 10 ** ($fromDigits - $toDigits) : 1;

        return MoneyMath::mulDivRound($amountMinor, $rateE8 * $up, self::SCALE * $down);
    }

    /** The rate the other way round: 1 / rate, rounded to eight places. */
    public static function invert(int $rateE8): int
    {
        self::assertRate($rateE8);

        return Money::divideRound(self::SCALE * self::SCALE, $rateE8);
    }

    /** "18.4649" -> 1846490000. At most eight decimals; anything else is refused, never rounded. */
    public static function parseRate(string $rate): int
    {
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,8}))?$/', trim($rate), $m) !== 1) {
            throw new InvalidArgumentException("Not a valid exchange rate: {$rate}");
        }

        $value = ((int) $m[1]) * self::SCALE + (int) str_pad($m[2] ?? '', 8, '0');

        self::assertRate($value);

        return $value;
    }

    /** 1846490000 -> "18.4649" (trailing zeros dropped, at least one decimal kept off when whole). */
    public static function formatRate(int $rateE8): string
    {
        $whole = intdiv($rateE8, self::SCALE);
        $fraction = rtrim(str_pad((string) ($rateE8 % self::SCALE), 8, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : "{$whole}.{$fraction}";
    }

    /**
     * Realised exchange difference when a foreign-currency amount is settled: the base-currency value at
     * the settlement rate against its value at the rate it was booked at. Positive is a gain.
     *
     * $kind 'asset' is something owed to us (a receivable: a higher rate at settlement is a gain);
     * 'liability' is something we owe (a payable: a higher rate at settlement is a loss).
     */
    public static function realised(int $foreignMinor, int $bookRateE8, int $settleRateE8, int $foreignDigits, int $baseDigits, string $kind): int
    {
        $atBook = self::convert($foreignMinor, $bookRateE8, $foreignDigits, $baseDigits);
        $atSettle = self::convert($foreignMinor, $settleRateE8, $foreignDigits, $baseDigits);

        return self::signed($atSettle - $atBook, $kind);
    }

    /**
     * Unrealised exchange difference at a reporting date: what the open foreign balance is worth at the
     * closing rate against the base-currency amount it is currently carried at. Positive is a gain.
     */
    public static function unrealised(int $openForeignMinor, int $carryingBaseMinor, int $closingRateE8, int $foreignDigits, int $baseDigits, string $kind): int
    {
        $atClosing = self::convert($openForeignMinor, $closingRateE8, $foreignDigits, $baseDigits);

        return self::signed($atClosing - $carryingBaseMinor, $kind);
    }

    private static function signed(int $increaseInBaseValue, string $kind): int
    {
        return match ($kind) {
            'asset' => $increaseInBaseValue,
            'liability' => -$increaseInBaseValue,
            default => throw new InvalidArgumentException("Kind must be asset or liability, not {$kind}."),
        };
    }

    private static function assertRate(int $rateE8): void
    {
        if ($rateE8 <= 0) {
            throw new InvalidArgumentException('An exchange rate must be greater than zero.');
        }
    }
}
