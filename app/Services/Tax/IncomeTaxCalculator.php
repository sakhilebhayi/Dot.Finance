<?php

namespace App\Services\Tax;

use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Income tax arithmetic from the rule book's band tables. Pure given a RuleSession: no database of its own, integers only,
 * half away from zero. The "clock" matters: a company is taxed at the rate for its YEAR OF ASSESSMENT END DATE, an individual at the
 * table for the tax year (1 March to end February), which is why every method takes the year end.
 */
final class IncomeTaxCalculator
{
    /**
     * Tax on $taxableCents from a band table [{above_cents, up_to_cents|null, base_cents, rate_bp}].
     * A figure exactly on a band's upper limit belongs to that lower band (the next band's base equals the tax there).
     *
     * @param  list<array{above_cents: int, up_to_cents: ?int, base_cents: int, rate_bp: int}>  $bands
     */
    public function onBands(array $bands, int $taxableCents): int
    {
        if ($taxableCents <= 0) {
            return 0;
        }

        foreach ($bands as $band) {
            $upTo = $band['up_to_cents'];
            if ($upTo === null || $taxableCents <= $upTo) {
                return $band['base_cents'] + Money::mulDivRound($taxableCents - $band['above_cents'], $band['rate_bp'], 10_000);
            }
        }

        throw new InvalidArgumentException('The band table has no open-ended top band.');
    }

    /** @return array{tax_cents: int, rate_bp: int, rule: string} */
    public function company(RuleSession $rules, int $taxableCents, CarbonImmutable $yearEnd): array
    {
        $rule = $rules->rule('cit.rate_bp', $yearEnd);

        return ['tax_cents' => $taxableCents <= 0 ? 0 : Money::mulDivRound($taxableCents, $rule->basisPoints(), 10_000), 'rate_bp' => $rule->basisPoints(), 'rule' => $rule->version()];
    }

    /**
     * Small business corporation tables.
     *
     * @return array{tax_cents: int, eligible: bool, reasons: list<string>, rule: string}
     */
    public function sbc(RuleSession $rules, int $taxableCents, int $grossIncomeCents, ?bool $naturalPersonShareholders, CarbonImmutable $yearEnd): array
    {
        $bands = $rules->rule('sbc.bands', $yearEnd)->json();
        $limit = $rules->rule('sbc.gross_income_limit_cents', $yearEnd)->cents();
        $reasons = [];
        if ($grossIncomeCents > $limit) {
            $reasons[] = 'Gross income of '.Money::format($grossIncomeCents, 'ZAR').' is above the limit of '.Money::format($limit, 'ZAR').'.';
        }
        if ($naturalPersonShareholders !== true) {
            $reasons[] = $naturalPersonShareholders === null ? 'The profile does not say whether all shareholders are natural persons.' : 'All shareholders must be natural persons.';
        }

        return ['tax_cents' => $this->onBands($bands, $taxableCents), 'eligible' => $reasons === [], 'reasons' => $reasons, 'rule' => $rules->rule('sbc.bands', $yearEnd)->version()];
    }

    /**
     * Turnover tax on qualifying turnover (not on profit).
     *
     * @return array{tax_cents: int, within_limit: bool, limit_cents: int, rule: string}
     */
    public function turnoverTax(RuleSession $rules, int $qualifyingTurnoverCents, string $taxpayer, CarbonImmutable $yearEnd): array
    {
        $rule = $rules->rule('tot.bands', $yearEnd, $taxpayer);
        $limit = $rules->rule('tot.qualifying_limit_cents', $yearEnd)->cents();

        return ['tax_cents' => $this->onBands($rule->json(), $qualifyingTurnoverCents), 'within_limit' => $qualifyingTurnoverCents <= $limit, 'limit_cents' => $limit, 'rule' => $rule->version()];
    }

    /**
     * An individual's (or sole proprietor's) normal tax: the band tax less the rebates for the age band. Medical credits,
     * retirement deductions and other personal items are NOT included.
     *
     * @return array{before_rebates_cents: int, rebates_cents: int, tax_cents: int, threshold_cents: int, rule: string}
     */
    public function individual(RuleSession $rules, int $taxableCents, string $ageBand, CarbonImmutable $yearEnd): array
    {
        $bands = $rules->rule('pit.bands', $yearEnd);
        $before = $this->onBands($bands->json(), $taxableCents);

        $rebates = $rules->rule('pit.rebate.primary_cents', $yearEnd)->cents();
        $threshold = $rules->rule('pit.threshold.under65_cents', $yearEnd)->cents();
        if (in_array($ageBand, ['65_to_74', '75_plus'], true)) {
            $rebates += $rules->rule('pit.rebate.secondary_cents', $yearEnd)->cents();
            $threshold = $rules->rule('pit.threshold.65_to_74_cents', $yearEnd)->cents();
        }
        if ($ageBand === '75_plus') {
            $rebates += $rules->rule('pit.rebate.tertiary_cents', $yearEnd)->cents();
            $threshold = $rules->rule('pit.threshold.75_plus_cents', $yearEnd)->cents();
        }

        return ['before_rebates_cents' => $before, 'rebates_cents' => $rebates, 'tax_cents' => max(0, $before - $rebates), 'threshold_cents' => $threshold, 'rule' => $bands->version()];
    }

    /** @return array{tax_cents: int, rate_bp: int, rule: string} */
    public function trust(RuleSession $rules, int $taxableCents, CarbonImmutable $yearEnd): array
    {
        $rule = $rules->rule('pit.trust_rate_bp', $yearEnd);

        return ['tax_cents' => $taxableCents <= 0 ? 0 : Money::mulDivRound($taxableCents, $rule->basisPoints(), 10_000), 'rate_bp' => $rule->basisPoints(), 'rule' => $rule->version()];
    }

    /**
     * Assessed-loss set-off: limited to a fixed amount plus a percentage of the taxable income above it (low confidence rule).
     *
     * @return array{setoff_cents: int, remaining_loss_cents: int, taxable_after_cents: int, cap_cents: int}
     */
    public function assessedLoss(RuleSession $rules, int $taxableBeforeCents, int $lossBroughtForwardCents, CarbonImmutable $on): array
    {
        $spec = $rules->rule('cit.assessed_loss.setoff', $on)->json();
        $fixed = (int) $spec['fixed_cents'];
        $pct = (int) $spec['balance_pct_bp'];

        if ($taxableBeforeCents <= 0 || $lossBroughtForwardCents <= 0) {
            return ['setoff_cents' => 0, 'remaining_loss_cents' => max(0, $lossBroughtForwardCents), 'taxable_after_cents' => $taxableBeforeCents, 'cap_cents' => 0];
        }

        $cap = $taxableBeforeCents <= $fixed ? $taxableBeforeCents : $fixed + Money::mulDivRound($taxableBeforeCents - $fixed, $pct, 10_000);
        $setoff = min($lossBroughtForwardCents, $cap);

        return ['setoff_cents' => $setoff, 'remaining_loss_cents' => $lossBroughtForwardCents - $setoff, 'taxable_after_cents' => $taxableBeforeCents - $setoff, 'cap_cents' => $cap];
    }
}
