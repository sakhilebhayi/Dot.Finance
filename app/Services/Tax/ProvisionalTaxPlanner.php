<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxProfile;
use App\Models\Tax\TaxProvisionalPayment;
use App\Models\User;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\Jurisdiction\JurisdictionRegistry;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The provisional tax SCHEDULE and PENALTY checks. Always an estimate: the estimates are the person's own, the tax on them is
 * the entity's table, and the penalties are worked out the way the SARS guide (GEN-PT-01-G01 rev 28) describes them.
 *
 * Correction to a common shorthand: "80 percent / 90 percent" is the PENALTY trigger, not a rule about the payment.
 *  - Taxable income ABOVE R1m: no penalty if the second estimate is at least 80 percent of actual taxable income; otherwise 20 percent of
 *    (tax on 80 percent of actual taxable income less the tax already paid).
 *  - Taxable income UP TO R1m (R1m itself included): a penalty only if the estimate is under 90 percent of actual AND under the basic amount;
 *    then 20 percent of (tax on the lesser of 90 percent of actual and the basic amount, less the tax already paid).
 *  - The basic amount is the last assessed taxable income (less the taxable capital gain), raised 8 percent when the estimate is made more
 *    than 18 months after that year ended. The research does not say whether the 8 percent compounds for each further year; it is applied once.
 *  - The third payment is optional and carries no penalty. Late payment of the first or second carries 10 percent.
 *
 * Not modelled: credits (employees' tax, foreign tax, other rebates), interest, the rules for individuals under the thresholds.
 */
final class ProvisionalTaxPlanner
{
    public function __construct(
        private readonly TaxRules $rules,
        private readonly IncomeTaxEstimator $estimator,
        private readonly JurisdictionRegistry $jurisdictions,
    ) {}

    // ── pure calculations ───────────────────────────────────────────────

    /**
     * The basic amount: last assessed taxable income, less the taxable capital gain, raised 8 percent once when the estimate is made
     * more than 18 months after the end of that year.
     *
     * @return array{cents: int, uplifted: bool, months: int}
     */
    public function basicAmount(RuleSession $rules, int $lastAssessedTaxableCents, int $taxableCapitalGainCents, CarbonImmutable $lastAssessedYearEnd, CarbonImmutable $estimateMadeOn): array
    {
        $base = max(0, $lastAssessedTaxableCents - $taxableCapitalGainCents);
        $months = (int) $lastAssessedYearEnd->diffInMonths($estimateMadeOn);
        $uplift = $rules->rule('pt.basic_amount_uplift_bp', $estimateMadeOn)->basisPoints();
        // "more than 18 months": 18 months to the day is not more
        $over = $estimateMadeOn->gt($lastAssessedYearEnd->addMonths(18));

        return ['cents' => $over ? $base + Money::mulDivRound($base, $uplift, 10_000) : $base, 'uplifted' => $over, 'months' => $months];
    }

    /**
     * The underestimation penalty. $taxOn turns a taxable income into tax for this entity.
     *
     * @param  callable(int): int  $taxOn
     * @return array{penalty_cents: int, applies: bool, rule: string, trigger_cents: int, explanation: string}
     */
    public function underestimationPenalty(RuleSession $rules, int $estimateTaxableCents, int $actualTaxableCents, int $basicAmountCents, int $taxPaidCents, callable $taxOn, CarbonImmutable $on): array
    {
        $pct = $rules->rule('pt.penalty_underestimate_bp', $on)->basisPoints();
        $threshold = $rules->rule('pt.estimate_threshold_cents', $on)->cents();
        $aboveFloor = $rules->rule('pt.estimate_floor_above_threshold_bp', $on)->basisPoints();
        $upToFloor = $rules->rule('pt.estimate_floor_up_to_threshold_bp', $on)->basisPoints();

        if ($actualTaxableCents > $threshold) {
            $trigger = Money::mulDivRound($actualTaxableCents, $aboveFloor, 10_000);
            if ($estimateTaxableCents >= $trigger) {
                return ['penalty_cents' => 0, 'applies' => false, 'rule' => 'above R1m, 80 percent', 'trigger_cents' => $trigger, 'explanation' => 'The estimate is at least 80 percent of actual taxable income: no underestimation penalty.'];
            }
            $shortfall = max(0, $taxOn($trigger) - $taxPaidCents);

            return ['penalty_cents' => Money::mulDivRound($shortfall, $pct, 10_000), 'applies' => true, 'rule' => 'above R1m, 80 percent', 'trigger_cents' => $trigger,
                'explanation' => '20 percent of the tax on 80 percent of actual taxable income ('.Money::format($taxOn($trigger), 'ZAR').') less the tax already paid ('.Money::format($taxPaidCents, 'ZAR').').'];
        }

        $ninety = Money::mulDivRound($actualTaxableCents, $upToFloor, 10_000);
        if ($estimateTaxableCents >= $ninety || $estimateTaxableCents >= $basicAmountCents) {
            return ['penalty_cents' => 0, 'applies' => false, 'rule' => 'up to R1m, 90 percent or basic amount', 'trigger_cents' => min($ninety, $basicAmountCents),
                'explanation' => 'The estimate is at least 90 percent of actual taxable income or at least the basic amount: no underestimation penalty.'];
        }

        $base = min($ninety, $basicAmountCents);
        $shortfall = max(0, $taxOn($base) - $taxPaidCents);

        return ['penalty_cents' => Money::mulDivRound($shortfall, $pct, 10_000), 'applies' => true, 'rule' => 'up to R1m, 90 percent or basic amount', 'trigger_cents' => $base,
            'explanation' => '20 percent of the tax on the lesser of 90 percent of actual taxable income and the basic amount ('.Money::format($taxOn($base), 'ZAR').') less the tax already paid ('.Money::format($taxPaidCents, 'ZAR').').'];
    }

    /** 10 percent of what was unpaid at the due date, for the first and second periods only. */
    public function latePaymentPenalty(RuleSession $rules, int $period, int $amountDueCents, int $paidByDueDateCents, CarbonImmutable $on): int
    {
        if ($period === 3) {
            return 0;
        }

        return Money::mulDivRound(max(0, $amountDueCents - $paidByDueDateCents), $rules->rule('pt.penalty_late_payment_bp', $on)->basisPoints(), 10_000);
    }

    // ── schedule (database) ─────────────────────────────────────────────

    /**
     * The three periods of a tax year with their due dates, the person's saved estimates and payments, and the amounts they imply.
     *
     * @return list<array<string, mixed>>
     */
    public function schedule(LedgerContext $ctx, TaxProfile $profile, CarbonImmutable $yearEnd): array
    {
        $pack = $this->jurisdictions->get($profile->jurisdiction);
        $year = $pack->taxYear($profile->legal_entity, $profile->financial_year_end_month, $yearEnd);
        $rules = $this->rules->session($ctx, $profile->jurisdiction);
        $dueDates = new DueDates(new BusinessCalendar($profile->jurisdiction));
        $rows = TaxProvisionalPayment::query()->forContext($ctx)->where('tax_year_end', $year['end']->toDateString())->get()->keyBy('period');
        $today = CarbonImmutable::today();
        $out = [];

        foreach ([1, 2, 3] as $period) {
            $due = $dueDates->provisional($rules, $year['start'], $year['end'], $period);
            $row = $rows->get($period);
            $paid = (int) ($row?->paid_cents ?? 0);
            $late = 0;
            if ($row && $row->amount_due_cents !== null && $period !== 3) {
                $paidOnTime = ($row->paid_on && CarbonImmutable::parse($row->paid_on->toDateString())->lte($due->due)) ? $paid : 0;
                $overdue = $today->gt($due->due) || ($row->paid_on !== null);
                $late = $overdue ? $this->latePaymentPenalty($rules, $period, (int) $row->amount_due_cents, $paidOnTime, $due->due) : 0;
            }
            $out[] = [
                'period' => $period, 'label' => ['', 'First payment', 'Second payment', 'Third payment (optional top-up)'][$period],
                'due' => $due, 'row' => $row, 'paid_cents' => $paid, 'late_penalty_cents' => $late,
                'status' => $row?->paid_on ? 'paid' : ($today->gt($due->due) ? 'overdue' : ($today->gte($due->due->subDays(14)) ? 'due' : 'upcoming')),
            ];
        }

        return $out;
    }

    /**
     * Save the person's own taxable income estimate for a period; the tax and the amount due are worked out from it.
     */
    public function saveEstimate(LedgerContext $ctx, TaxProfile $profile, CarbonImmutable $yearEnd, int $period, int $estimateTaxableCents, ?int $basicAmountCents, ?CarbonImmutable $basicYearEnd, User $by): TaxProvisionalPayment
    {
        if (! in_array($period, [1, 2, 3], true)) {
            throw new TaxException('Provisional tax has three periods: 1, 2 and 3.');
        }
        if ($profile->tax_regime === 'turnover_tax') {
            throw new TaxException('A turnover tax business does not pay provisional tax.');
        }

        $pack = $this->jurisdictions->get($profile->jurisdiction);
        $year = $pack->taxYear($profile->legal_entity, $profile->financial_year_end_month, $yearEnd);
        $rules = $this->rules->session($ctx, $profile->jurisdiction);
        $due = (new DueDates(new BusinessCalendar($profile->jurisdiction)))->provisional($rules, $year['start'], $year['end'], $period);
        $tax = $this->estimator->tax($rules, $profile, max(0, $estimateTaxableCents), 0, $year['end'])[0];

        return DB::transaction(function () use ($ctx, $year, $period, $estimateTaxableCents, $basicAmountCents, $basicYearEnd, $tax, $due, $by) {
            $existing = TaxProvisionalPayment::query()->forContext($ctx)->where('tax_year_end', $year['end']->toDateString())->get()->keyBy('period');
            $prior = function (int $p) use ($existing): int {
                $r = $existing->get($p);

                return $r === null ? 0 : (int) ($r->paid_on !== null ? $r->paid_cents : ($r->amount_due_cents ?? 0));
            };

            $amountDue = match ($period) {
                1 => Money::divideRound($tax, 2),
                2 => max(0, $tax - $prior(1)),
                3 => max(0, $tax - $prior(1) - $prior(2)),
            };

            $row = $existing->get($period) ?? new TaxProvisionalPayment($ctx->ownerAttributes() + ['tax_year_end' => $year['end']->toDateString(), 'period' => $period]);
            $row->fill([
                'due_date' => $due->due->toDateString(), 'estimate_taxable_income_cents' => $estimateTaxableCents, 'basic_amount_cents' => $basicAmountCents,
                'basic_amount_year_end' => $basicYearEnd?->toDateString(), 'tax_on_estimate_cents' => $tax, 'amount_due_cents' => $amountDue, 'updated_by_user_id' => $by->id,
            ])->save();

            FinanceAudit::record($ctx->ownerAttributes(), 'estimate_saved', 'tax_provisional', (int) $row->id, 'Provisional tax period '.$period.', year ending '.$year['end']->format('Y-m-d'), [
                'estimate_taxable_income_cents' => ['after' => $estimateTaxableCents], 'amount_due_cents' => ['after' => $amountDue],
            ], 'an estimate entered by a person', null, $by->id);

            return $row;
        });
    }

    public function recordPayment(LedgerContext $ctx, TaxProvisionalPayment $row, int $paidCents, CarbonImmutable $paidOn, ?string $reference, User $by): TaxProvisionalPayment
    {
        if ($paidCents <= 0) {
            throw new TaxException('The payment must be more than zero.');
        }
        if ($paidOn->gt(CarbonImmutable::today())) {
            throw new TaxException('The payment date cannot be in the future.');
        }

        $row->fill(['paid_cents' => $paidCents, 'paid_on' => $paidOn->toDateString(), 'payment_reference' => $reference !== null ? mb_substr(trim($reference), 0, 60) : null, 'updated_by_user_id' => $by->id])->save();
        FinanceAudit::record($ctx->ownerAttributes(), 'payment_recorded', 'tax_provisional', (int) $row->id, 'Provisional tax period '.$row->period, ['paid_cents' => ['after' => $paidCents]], 'recorded by a person', null, $by->id);

        return $row;
    }

    /** Taxable income -> tax for the profile's entity (what the penalty check needs). */
    public function taxFunction(LedgerContext $ctx, TaxProfile $profile, CarbonImmutable $yearEnd): \Closure
    {
        $rules = $this->rules->session($ctx, $profile->jurisdiction);

        return fn (int $taxable): int => $this->estimator->tax($rules, $profile, max(0, $taxable), 0, $yearEnd)[0];
    }
}
