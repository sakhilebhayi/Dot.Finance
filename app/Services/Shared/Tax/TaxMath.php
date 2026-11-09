<?php

namespace App\Services\Shared\Tax;

use App\Support\Money;
use App\Support\MoneyMath;
use InvalidArgumentException;

/**
 * The tax arithmetic, with no database and no rules lookup: every function takes the rate it is
 * told to use. All amounts are integer cents, all rates are basis points (1500 = 15 %), quantities are
 * integers scaled by 10^4 (1.5 = 15000), and every division rounds half away from zero. No floats.
 *
 * Two rounding policies, both exact:
 *  - line:     each line's tax is rounded on its own and the document total is their sum;
 *  - document: the tax of each (code, rate, price basis) group is rounded once on the group's
 *              total, then shared back to its lines by the largest-remainder method so the lines
 *              still add up to the document exactly (a line is never off by a cent from the total).
 */
final class TaxMath
{
    public const QUANTITY_SCALE = 10_000;

    public const BP = 10_000;

    /** "2", "1.5", "0.0125", "-3" -> scaled integer; more than four decimals is refused, not rounded. */
    public static function quantityToE4(string|int $quantity): int
    {
        $text = is_int($quantity) ? (string) $quantity : trim($quantity);

        if (preg_match('/^(-?)(\d{1,9})(?:\.(\d{1,4}))?$/', $text, $m) !== 1) {
            throw new InvalidArgumentException("Not a valid quantity: {$text}");
        }

        $scaled = ((int) $m[2]) * self::QUANTITY_SCALE + (int) str_pad($m[3] ?? '', 4, '0');

        return $m[1] === '-' ? -$scaled : $scaled;
    }

    /**
     * unit price x quantity, less discount. The discount is a percentage (basis points) and/or a flat
     * amount; it reduces the size of the line and can never take it past zero.
     *
     * @return array{before_discount: int, discount: int, amount: int}
     */
    public static function lineAmount(int $unitCents, string|int $quantity, int $discountBp = 0, int $discountCents = 0): array
    {
        if ($discountBp < 0 || $discountBp > self::BP) {
            throw new InvalidArgumentException('A percentage discount is between 0 and 100 percent.');
        }
        if ($discountCents < 0) {
            throw new InvalidArgumentException('A flat discount cannot be negative.');
        }

        $before = MoneyMath::mulDivRound($unitCents, self::quantityToE4($quantity), self::QUANTITY_SCALE);
        self::assertInRange($before);

        $magnitude = abs($before);
        $discount = MoneyMath::mulDivRound($magnitude, $discountBp, self::BP) + $discountCents;

        if ($discount > $magnitude) {
            throw new InvalidArgumentException('The discount is larger than the line.');
        }

        $amount = $before < 0 ? $before + $discount : $before - $discount;

        return ['before_discount' => $before, 'discount' => $discount, 'amount' => $amount];
    }

    /** Price excludes tax: tax = amount x rate. @return array{net: int, tax: int, gross: int} */
    public static function exclusive(int $amountCents, int $rateBp): array
    {
        self::assertInRange($amountCents);
        $tax = MoneyMath::mulDivRound($amountCents, $rateBp, self::BP);

        return ['net' => $amountCents, 'tax' => $tax, 'gross' => $amountCents + $tax];
    }

    /** Price includes tax: net = gross / (1 + rate), tax is what is left. @return array{net: int, tax: int, gross: int} */
    public static function inclusive(int $grossCents, int $rateBp): array
    {
        self::assertInRange($grossCents);
        $net = MoneyMath::mulDivRound($grossCents, self::BP, self::BP + $rateBp);

        return ['net' => $net, 'tax' => $grossCents - $net, 'gross' => $grossCents];
    }

    /**
     * Document-level rounding for one group of lines sharing a rate and a price basis: the group's tax
     * is rounded once, then split across the lines (largest remainder, ties to the earlier line) so
     * the line taxes add up to it exactly.
     *
     * @param  list<int>  $amounts  each line's amount after discount (net if exclusive, gross if inclusive)
     * @return list<int> the tax of each line, in the same order
     */
    public static function documentGroupTax(array $amounts, int $rateBp, bool $inclusive): array
    {
        if ($amounts === []) {
            return [];
        }

        if ($rateBp < 0 || $rateBp > 50_000) {
            throw new InvalidArgumentException('A tax rate is between 0 and 500 percent.');
        }

        $total = array_sum($amounts);
        self::assertInRange($total);

        if (! $inclusive) {
            // tax_i = amount_i x rate / BP
            $target = MoneyMath::mulDivRound($total, $rateBp, self::BP);

            return self::largestRemainder(array_map(fn (int $a) => $a * $rateBp, $amounts), self::BP, $target);
        }

        // net_i = amount_i x BP / (BP + rate); tax_i = amount_i - net_i
        $netTarget = MoneyMath::mulDivRound($total, self::BP, self::BP + $rateBp);
        $nets = self::largestRemainder(array_map(fn (int $a) => $a * self::BP, $amounts), self::BP + $rateBp, $netTarget);

        return array_map(fn (int $amount, int $net) => $amount - $net, $amounts, $nets);
    }

    /**
     * Split a rounded total across lines: each line gets the floor of its exact share, and the cents
     * left over go one each to the lines with the largest fractional remainders.
     *
     * @param  list<int>  $numerators  each line's exact share is numerator / denominator
     * @return list<int>
     */
    public static function largestRemainder(array $numerators, int $denominator, int $target): array
    {
        $floors = [];
        $remainders = [];
        foreach ($numerators as $i => $numerator) {
            $floors[$i] = MoneyMath::floorDiv($numerator, $denominator);
            $remainders[$i] = $numerator - $floors[$i] * $denominator;
        }

        $leftover = $target - array_sum($floors);

        if ($leftover < 0 || $leftover > count($numerators)) {
            throw new InvalidArgumentException('The rounded total does not fit the lines (internal inconsistency).');
        }

        $order = array_keys($remainders);
        usort($order, fn (int $x, int $y) => $remainders[$y] <=> $remainders[$x] ?: $x <=> $y);

        foreach (array_slice($order, 0, $leftover) as $i) {
            $floors[$i]++;
        }

        return array_values($floors);
    }

    private static function assertInRange(int $cents): void
    {
        if (abs($cents) > Money::MAX_CENTS) {
            throw new InvalidArgumentException('Amount is outside the supported range.');
        }
    }

    /**
     * Progressive tax from a bands table (income tax for individuals, small business corporations,
     * turnover tax). Each band says: above this much, up to that much, the tax is a base amount plus a
     * rate on the part above the band's start. Cents in, cents out.
     *
     * @param  list<array{above_cents: int, up_to_cents: ?int, base_cents: int, rate_bp: int}>  $bands
     */
    public static function bandedTax(array $bands, int $incomeCents): int
    {
        if ($incomeCents <= 0) {
            return 0;
        }

        foreach ($bands as $band) {
            if ($incomeCents > $band['above_cents'] && ($band['up_to_cents'] === null || $incomeCents <= $band['up_to_cents'])) {
                return $band['base_cents'] + MoneyMath::mulDivRound($incomeCents - $band['above_cents'], $band['rate_bp'], self::BP);
            }
        }

        throw new InvalidArgumentException('The bands table has no band for this income (a table must cover every amount).');
    }
}
