<?php

namespace App\Support;

/**
 * Plain words for facts the screens show as figures, kept in one place so every page says the same thing (R-44 polish, D-1701).
 */
final class FigureWords
{
    /** The account type as a person would say it, in sentence case. */
    public static function accountType(string $type): string
    {
        return match ($type) {
            'checking' => 'Checking account',
            'savings' => 'Savings account',
            'credit' => 'Credit card or loan',
            'investment' => 'Investment, not cash',
            'cash' => 'Cash on hand',
            default => ucfirst($type),
        };
    }

    /**
     * What a party's open balance is, in words, so a customer is never "owed (522.00)": a negative receivable is a credit
     * the customer holds, a positive one is what they owe. Returns ['label' => ..., 'cents' => positive amount, 'credit' => bool].
     *
     * @return array{label: string, cents: int, credit: bool}
     */
    public static function owedBy(int $receivableCents): array
    {
        return $receivableCents < 0
            ? ['label' => 'Credit on account', 'cents' => -$receivableCents, 'credit' => true]
            : ['label' => 'Owes you', 'cents' => $receivableCents, 'credit' => false];
    }

    /** The same for a supplier's payable balance (positive is what you owe, negative is credit you hold with them). @return array{label: string, cents: int, credit: bool} */
    public static function owedTo(int $payableCents): array
    {
        return $payableCents < 0
            ? ['label' => 'Credit with supplier', 'cents' => -$payableCents, 'credit' => true]
            : ['label' => 'You owe', 'cents' => $payableCents, 'credit' => false];
    }

    /**
     * A short axis label for an amount in cents ("236k", "1.3m"), integer arithmetic only. Display only: nothing reads it back.
     */
    public static function axis(int $cents): string
    {
        $v = intdiv(abs($cents), 100);
        $sign = $cents < 0 ? '-' : '';

        if ($v >= 1_000_000) {
            $t = intdiv($v, 100_000);

            return $sign.intdiv($t, 10).($t % 10 === 0 ? '' : '.'.($t % 10)).'m';
        }
        if ($v >= 10_000) {
            return $sign.intdiv($v, 1000).'k';
        }
        if ($v >= 1000) {
            $t = intdiv($v, 100);

            return $sign.intdiv($t, 10).($t % 10 === 0 ? '' : '.'.($t % 10)).'k';
        }

        return $sign.$v;
    }

    /**
     * A round gridline step, in cents, so that `$intervals` of it cover the peak: 0, 60k, 120k, 180k, 240k rather than quarters of
     * the peak (0, 58k, 117k, 176k, 235k). Steps are 1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8 times a power of ten. Integer arithmetic only.
     */
    public static function niceStep(int $peakCents, int $intervals = 4): int
    {
        $peak = max(1, $peakCents);
        $intervals = max(1, $intervals);

        for ($power = 100; ; $power *= 10) {
            foreach ([1000, 1200, 1500, 2000, 2500, 3000, 4000, 5000, 6000, 8000] as $mult) {
                $step = intdiv($mult * $power, 1000);
                if ($step * $intervals >= $peak) {
                    return $step;
                }
            }
        }
    }
}
