<?php

namespace App\Support;

/**
 * Plain words for a tax code and for the rule versions a calculation used. A person reads "VAT 15% standard-rated", never
 * ZA_VAT_STD or tax_rules#25; the identifiers stay available behind a "Show working" disclosure.
 */
final class TaxWords
{
    /** @var array<string, string> code => what the treatment is called, without the rate */
    private const TREATMENT = [
        'ZA_VAT_STD' => 'standard-rated', 'ZA_VAT_CAPITAL' => 'standard-rated, capital goods', 'ZA_VAT_IMPORT' => 'imported goods', 'ZA_VAT_IMPORT_CAPITAL' => 'imported capital goods',
        'ZA_VAT_ZERO' => 'zero-rated', 'ZA_VAT_ZERO_EXPORT' => 'zero-rated, exported goods', 'ZA_VAT_EXEMPT' => 'exempt', 'ZA_VAT_OUT_OF_SCOPE' => 'outside the scope of VAT',
        'ZA_VAT_DENIED_ENTERTAINMENT' => 'standard-rated, entertainment (not claimable)', 'ZA_VAT_DENIED_MOTOR_CAR' => 'standard-rated, motor car (not claimable)',
    ];

    /** "VAT 15% standard-rated", "VAT 0% zero-rated", "VAT exempt". */
    public static function label(?string $code, ?int $rateBp = null): string
    {
        if ($code === null || $code === '') {
            return 'No VAT';
        }

        $treatment = self::TREATMENT[$code] ?? strtolower(str_replace('_', ' ', preg_replace('/^[A-Z]{2}_(VAT_)?/', '', $code) ?? $code));
        if ($code === 'ZA_VAT_OUT_OF_SCOPE') {
            return 'Outside the scope of VAT';
        }
        if ($code === 'ZA_VAT_EXEMPT' || $rateBp === null) {
            return 'VAT '.$treatment;
        }

        return 'VAT '.rtrim(rtrim(number_format($rateBp / 100, 2, '.', ''), '0'), '.').'% '.$treatment;
    }

    /**
     * One sentence about the rules a calculation used, in words. The raw identifiers are returned separately for the working.
     *
     * @param  list<string|int>|null  $versions  e.g. ["tax_rules#25", "tax_rates#1", "tax_codes#1"]
     */
    public static function rulesSentence(?array $versions): string
    {
        return $versions === null || $versions === [] ? 'Calculated from the South African VAT rules held in Dot.Finance.' : 'Calculated from the South African VAT rules that applied on the document date.';
    }

    /** @param  list<string|int>|null  $versions */
    public static function working(?array $versions): string
    {
        return implode(', ', array_map('strval', $versions ?? []));
    }
}
