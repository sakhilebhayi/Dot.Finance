<?php

namespace App\Services\Sales\Support;

use App\Services\Shared\FxMath;

/**
 * Folds document lines into the groups the journal is posted in, and converts each group to the base currency.
 * The same function is used when a document is worked out and when it is posted, so the two can never disagree
 * by a cent: what is posted is always what the document says.
 *
 *   income groups: one per (income account, tax code)  -> Cr income (net)
 *   tax groups:    one per tax code                    -> Cr output VAT (tax)
 *   base total  =  sum of the converted income groups + sum of the converted tax groups
 *
 * Each group is converted on its own (half away from zero), so the base total is the sum of what is posted rather
 * than a separate conversion of the total that might differ by a cent or two.
 */
final class PostingGroups
{
    /**
     * @param  list<array{income: int|string, tax_code_id: ?int, tax_code: ?string, net: int, tax: int}>  $lines  income is an account id or the system key
     * @return array{income: list<array<string, mixed>>, tax: list<array<string, mixed>>, base_net: int, base_tax: int}
     */
    public static function fold(array $lines, ?int $rateE8, int $foreignDigits = 2, int $baseDigits = 2): array
    {
        $income = [];
        $tax = [];

        foreach ($lines as $l) {
            $key = $l['income'].'|'.($l['tax_code_id'] ?? '-');
            $income[$key] ??= ['account' => $l['income'], 'tax_code_id' => $l['tax_code_id'], 'tax_code' => $l['tax_code'], 'net' => 0];
            $income[$key]['net'] += $l['net'];

            $tkey = (string) ($l['tax_code_id'] ?? '-');
            $tax[$tkey] ??= ['tax_code_id' => $l['tax_code_id'], 'tax_code' => $l['tax_code'], 'tax' => 0];
            $tax[$tkey]['tax'] += $l['tax'];
        }

        $convert = fn (int $amount) => $rateE8 === null ? $amount : FxMath::convert($amount, $rateE8, $foreignDigits, $baseDigits);

        $incomeOut = [];
        $baseNet = 0;
        foreach ($income as $g) {
            if ($g['net'] === 0) {
                continue;
            }
            $g['base'] = $convert($g['net']);
            $baseNet += $g['base'];
            $incomeOut[] = $g;
        }

        $taxOut = [];
        $baseTax = 0;
        foreach ($tax as $g) {
            if ($g['tax'] === 0) {
                continue;
            }
            $g['base'] = $convert($g['tax']);
            $baseTax += $g['base'];
            $taxOut[] = $g;
        }

        return ['income' => $incomeOut, 'tax' => $taxOut, 'base_net' => $baseNet, 'base_tax' => $baseTax];
    }
}
