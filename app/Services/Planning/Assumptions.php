<?php

namespace App\Services\Planning;

use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The what-if assumptions a scenario can hold, validated and reduced to integers. A scenario is only a list of these;
 * CashProjection applies them to a projection of the future and never to the ledger.
 *
 * Shapes (all money in minor units, all percentages in basis points, all dates Y-m-d):
 *   collect_faster  {days}                         customers pay N days sooner than expected
 *   delay_receipts  {days}                         customers pay N days later than expected
 *   delay_payment   {days}                         supplier bills are paid N days later
 *   new_cost        {name, amount_cents, start_on, months|null}   a monthly cost (a hire, a lease) from a date
 *   one_off         {name, direction in|out, amount_cents, on}    a single receipt or payment
 *   change_recurring{direction income|expense, bp} recurring income or expense rules change by bp (a price change)
 */
final class Assumptions
{
    public const MAX = 12;

    public const TYPES = [
        'collect_faster' => 'Customers pay sooner',
        'delay_receipts' => 'Customers pay later',
        'delay_payment' => 'Pay supplier bills later',
        'new_cost' => 'A new monthly cost (a hire, a lease)',
        'one_off' => 'A one-off receipt or payment',
        'change_recurring' => 'Change recurring income or expense (a price change)',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $input  as a form or an API sends it (strings)
     * @return list<array<string, mixed>>
     */
    public static function normalise(array $input): array
    {
        $out = [];
        foreach (array_values($input) as $i => $a) {
            if (! is_array($a) || ($a['type'] ?? '') === '') {
                continue;
            }
            $type = (string) $a['type'];
            $n = $i + 1;
            if (! array_key_exists($type, self::TYPES)) {
                throw new InvalidArgumentException("Assumption {$n}: unknown type.");
            }

            $out[] = ['type' => $type] + match ($type) {
                'collect_faster', 'delay_receipts', 'delay_payment' => ['days' => self::int($a['days'] ?? null, 1, 90, "Assumption {$n}: days must be a whole number from 1 to 90.")],
                'new_cost' => [
                    'name' => self::text($a['name'] ?? '', "Assumption {$n}: name the cost."),
                    'amount_cents' => self::money($a['amount'] ?? $a['amount_cents'] ?? null, "Assumption {$n}: the amount must be greater than zero, for example 25000.00."),
                    'start_on' => self::date($a['start_on'] ?? null, "Assumption {$n}: the start date must be a date."),
                    'months' => ($a['months'] ?? '') === '' ? null : self::int($a['months'], 1, 36, "Assumption {$n}: months must be from 1 to 36, or empty for every month."),
                ],
                'one_off' => [
                    'name' => self::text($a['name'] ?? '', "Assumption {$n}: name it."),
                    'direction' => in_array($a['direction'] ?? '', ['in', 'out'], true) ? $a['direction'] : throw new InvalidArgumentException("Assumption {$n}: choose receipt or payment."),
                    'amount_cents' => self::money($a['amount'] ?? $a['amount_cents'] ?? null, "Assumption {$n}: the amount must be greater than zero."),
                    'on' => self::date($a['on'] ?? null, "Assumption {$n}: the date must be a date."),
                ],
                'change_recurring' => [
                    'direction' => in_array($a['direction'] ?? '', ['income', 'expense'], true) ? $a['direction'] : throw new InvalidArgumentException("Assumption {$n}: choose income or expense."),
                    'bp' => self::int(self::bpFrom($a, $n), -9000, 20000, "Assumption {$n}: the change is between -90% and +200%."),
                ],
            };
        }

        if (count($out) > self::MAX) {
            throw new InvalidArgumentException('A scenario holds at most '.self::MAX.' assumptions.');
        }

        return $out;
    }

    /** A sentence for one assumption, for the scenario page and the comparison. */
    public static function describe(array $a, string $currency = ''): string
    {
        $m = fn (int $c) => Money::format($c, $currency ?: null);

        return match ($a['type']) {
            'collect_faster' => "Customers pay {$a['days']} days sooner than expected.",
            'delay_receipts' => "Customers pay {$a['days']} days later than expected.",
            'delay_payment' => "Supplier bills are paid {$a['days']} days later.",
            'new_cost' => "{$a['name']}: {$m($a['amount_cents'])} a month from {$a['start_on']}".($a['months'] ? " for {$a['months']} months." : ' with no end.'),
            'one_off' => "{$a['name']}: ".($a['direction'] === 'in' ? 'a receipt' : 'a payment')." of {$m($a['amount_cents'])} on {$a['on']}.",
            'change_recurring' => 'Recurring '.$a['direction'].' changes by '.self::bpText((int) $a['bp']).'.',
            default => $a['type'],
        };
    }

    public static function bpText(int $bp): string
    {
        $sign = $bp < 0 ? '-' : '+';
        $abs = abs($bp);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT).'%';
    }

    private static function bpFrom(array $a, int $n): int|string|null
    {
        if (isset($a['percent']) && $a['percent'] !== '') {
            try {
                return self::percentToBp($a['percent']);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException("Assumption {$n}: the percentage must be a number such as 5 or -2.5.");
            }
        }

        return $a['bp'] ?? null;
    }

    private static function percentToBp(mixed $percent): int
    {
        if (! is_string($percent) && ! is_int($percent)) {
            throw new InvalidArgumentException('The percentage must be a number such as 5 or -2.5.');
        }
        if (preg_match('/^(-?)(\d{1,3})(?:\.(\d{1,2}))?$/', trim((string) $percent), $m) !== 1) {
            throw new InvalidArgumentException('The percentage must be a number such as 5 or -2.5.');
        }
        $bp = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$bp : $bp;
    }

    private static function int(mixed $v, int $min, int $max, string $message): int
    {
        if (! is_int($v) && ! (is_string($v) && preg_match('/^-?\d+$/', $v) === 1)) {
            throw new InvalidArgumentException($message);
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            throw new InvalidArgumentException($message);
        }

        return $n;
    }

    private static function money(mixed $v, string $message): int
    {
        if (is_int($v)) {
            $cents = $v;
        } elseif (is_string($v) && Money::isValid($v)) {
            $cents = Money::parse($v);
        } else {
            throw new InvalidArgumentException($message);
        }

        if ($cents <= 0 || $cents > Money::MAX_CENTS) {
            throw new InvalidArgumentException($message);
        }

        return $cents;
    }

    private static function text(mixed $v, string $message): string
    {
        $t = trim((string) $v);
        if ($t === '' || mb_strlen($t) > 80) {
            throw new InvalidArgumentException($message);
        }

        return $t;
    }

    private static function date(mixed $v, string $message): string
    {
        if (! is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            throw new InvalidArgumentException($message);
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $v)->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException($message);
        }
    }
}
