<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxAdjustment;
use App\Models\Tax\TaxIncomeEstimate;
use App\Models\Tax\TaxProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\GlSync;
use App\Services\Tax\Dto\IncomeTaxEstimate;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\Jurisdiction\JurisdictionRegistry;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The income-tax ESTIMATE for a tax year to date, built from the profit and loss in the books, the adjustments a person
 * has entered (each with a reason), and the entity's tax table from the rule book.
 *
 *   accounting profit          Calculated   from the journals (or the cash ledger when accounting is off: Estimate)
 *   add-backs, deductions      You entered  each with a reason; "review prompts" suggest where to look
 *   taxable income             Estimate     profit + add-backs - deductions - exempt income, less an assessed loss
 *   tax                        Estimate     the entity's table at the year's end date
 *
 * Not modelled (listed on the result): capital gains, medical credits, retirement deductions, foreign income and
 * credits, fringe benefits, dividends tax, provisional payments already made, and anything specific to the entity.
 */
final class IncomeTaxEstimator
{
    public function __construct(
        private readonly TaxRules $rules,
        private readonly GlTaxLines $lines,
        private readonly IncomeTaxCalculator $calculator,
        private readonly GlSync $sync,
        private readonly JurisdictionRegistry $jurisdictions,
    ) {}

    /** The end date of the tax year that contains $date for this entity. */
    public function taxYearEnd(TaxProfile $profile, CarbonImmutable $date): CarbonImmutable
    {
        return $this->jurisdictions->get($profile->jurisdiction)->taxYear($profile->legal_entity, $profile->financial_year_end_month, $date)['end'];
    }

    public function estimate(LedgerContext $ctx, TaxProfile $profile, CarbonImmutable $yearEnd, ?CarbonImmutable $today = null): IncomeTaxEstimate
    {
        $today ??= CarbonImmutable::today();
        $pack = $this->jurisdictions->get($profile->jurisdiction);
        if (! $pack->isConfigured()) {
            throw new TaxException("{$pack->name()} is not configured: no income tax is calculated for it.");
        }

        $year = $pack->taxYear($profile->legal_entity, $profile->financial_year_end_month, $yearEnd);
        $start = $year['start'];
        $end = $year['end'];
        $rules = $this->rules->session($ctx, $profile->jurisdiction);
        $upTo = $today->lt($end) ? $today : $end;
        $daysInYear = (int) $start->diffInDays($end) + 1;
        $daysElapsed = max(1, min($daysInYear, (int) $start->diffInDays($upTo) + 1));
        $complete = $today->gte($end);

        // profit and loss
        $warnings = [];
        $flagged = [];
        $source = 'books';
        if ($this->sync->isEnabled($ctx)) {
            $pl = $this->lines->profitAndLossByAccount($ctx, $start->toDateString(), $upTo->toDateString());
            $income = (int) $pl->where('class', 'income')->sum('net_cents');
            $expense = (int) $pl->where('class', 'expense')->sum('net_cents');
            $flagged = $this->flag($ctx, $pl->where('class', 'expense'), $end);
        } else {
            $source = 'cash ledger';
            $warnings[] = 'Accounting is not switched on, so the profit is taken from the cash ledger (income less expenses recorded). Switch accounting on for a figure that ties to the books.';
            $base = Transaction::query()->forContext($ctx)->counted()->whereNull('transfer_group')->whereBetween('date', [$start->toDateString(), $upTo->toDateString()]);
            $income = (int) (clone $base)->where('type', 'income')->sum('amount_cents');
            $expense = (int) (clone $base)->where('type', 'expense')->sum('amount_cents');
        }
        $profit = $income - $expense;

        // adjustments
        $adjustments = TaxAdjustment::query()->forContext($ctx)->where('tax_year_end', $end->toDateString())->whereNull('voided_at')->orderBy('id')->get();
        $addBacks = (int) $adjustments->where('kind', 'add_back')->sum('amount_cents');
        $deductions = (int) $adjustments->where('kind', 'deduction')->sum('amount_cents');
        $exempt = (int) $adjustments->where('kind', 'exempt_income')->sum('amount_cents');
        $lossBf = (int) $adjustments->where('kind', 'assessed_loss_bf')->sum('amount_cents');
        $beforeLoss = $profit + $addBacks - $deductions - $exempt;

        $isCompany = $profile->isCompanyLike();
        $loss = ['setoff_cents' => 0, 'remaining_loss_cents' => $lossBf, 'taxable_after_cents' => $beforeLoss, 'cap_cents' => 0];
        $lossRule = null;
        if ($lossBf > 0 || $beforeLoss < 0) {
            $lossRule = $rules->rule('cit.assessed_loss.setoff', $end);
            if ($lossBf > 0) {
                $loss = $this->calculator->assessedLoss($rules, $beforeLoss, $lossBf, $end);
            }
            if ($lossBf > 0 || $beforeLoss < 0) {
                $warnings[] = 'Assessed-loss handling uses a rule of LOW confidence (the research could not re-confirm the R1m plus 80 percent set-off limit on a SARS page). Treat this part as a rough guide and confirm it.';
            }
            if (! $isCompany && $lossBf > 0) {
                $warnings[] = 'The set-off limit is the company rule. An individual\'s losses are ring-fenced in some trades (section 20A), which is not modelled.';
            }
        }

        $taxable = max(0, $loss['taxable_after_cents']);
        [$tax, $regimeLabel, $detail, $notModelled, $regimeWarnings] = $this->tax($rules, $profile, $taxable, $income, $end);
        array_push($warnings, ...$regimeWarnings);

        if ($beforeLoss < 0) {
            $warnings[] = 'The year to date shows a loss of '.Money::format(abs($beforeLoss), 'ZAR').'. An assessed loss may arise: it is only confirmed by SARS in an assessment.';
        }
        if (! $complete) {
            $warnings[] = 'The year is not over: this is the year to date ('.$start->format('j M Y').' to '.$upTo->format('j M Y').'), not a forecast. The projection below is a straight-line scale-up and a Suggestion only.';
        }
        if ($flagged !== [] && collect($flagged)->contains(fn ($f) => $f['decision'] === null)) {
            $warnings[] = 'Some expense accounts match a review prompt and have not been looked at yet (below).';
        }

        $projectedTaxable = $complete ? $taxable : (int) Money::mulDivRound(max(0, $beforeLoss), $daysInYear, $daysElapsed);
        $projectedTax = $complete ? $tax : $this->tax($rules, $profile, $projectedTaxable, (int) Money::mulDivRound($income, $daysInYear, $daysElapsed), $end)[0];

        $lines = [
            ['label' => 'Income in the books', 'cents' => $income, 'basis' => TaxBasis::CALCULATED, 'note' => "From the {$source}."],
            ['label' => 'Expenses in the books', 'cents' => -$expense, 'basis' => TaxBasis::CALCULATED, 'note' => null],
            ['label' => 'Accounting profit', 'cents' => $profit, 'basis' => TaxBasis::CALCULATED, 'note' => null],
            ['label' => 'Add back: not deductible', 'cents' => $addBacks, 'basis' => TaxBasis::ENTERED, 'note' => $adjustments->where('kind', 'add_back')->count().' adjustment(s), each with a reason.'],
            ['label' => 'Less: additional deductions', 'cents' => -$deductions, 'basis' => TaxBasis::ENTERED, 'note' => 'For example wear and tear allowances.'],
            ['label' => 'Less: exempt or non-taxable income', 'cents' => -$exempt, 'basis' => TaxBasis::ENTERED, 'note' => null],
            ['label' => 'Taxable income before an assessed loss', 'cents' => $beforeLoss, 'basis' => TaxBasis::ESTIMATE, 'note' => null],
            ['label' => 'Less: assessed loss brought forward, set off', 'cents' => -$loss['setoff_cents'], 'basis' => TaxBasis::ENTERED, 'note' => $lossBf > 0 ? 'Low-confidence rule.' : null],
            ['label' => 'Taxable income (estimate)', 'cents' => $taxable, 'basis' => TaxBasis::ESTIMATE, 'note' => null],
            ['label' => 'Income tax (estimate)', 'cents' => $tax, 'basis' => TaxBasis::ESTIMATE, 'note' => $regimeLabel],
        ];

        return new IncomeTaxEstimate(
            $start->toDateString(), $end->toDateString(), $profile->tax_regime === 'standard' ? $profile->legal_entity : $profile->tax_regime, $regimeLabel, $complete, $daysElapsed, $daysInYear,
            $income, $expense, $profit, $addBacks, $deductions, $exempt, $beforeLoss, $loss['setoff_cents'], $loss['remaining_loss_cents'], $taxable, $tax,
            $projectedTaxable, $projectedTax, $lines, $flagged,
            $adjustments->map(fn ($a) => ['id' => $a->id, 'kind' => $a->kind, 'category' => $a->category, 'label' => $a->label, 'amount_cents' => $a->amount_cents, 'reason' => $a->reason, 'gl_account_id' => $a->gl_account_id, 'created_at' => $a->created_at?->toDateTimeString()])->all(),
            array_values(array_unique($warnings)), $notModelled, $rules->versions(), $rules->lowestConfidence(), $source, $detail,
        );
    }

    /** Save the current estimate as a snapshot (history). */
    public function save(LedgerContext $ctx, TaxProfile $profile, IncomeTaxEstimate $e, string $label, User $by): TaxIncomeEstimate
    {
        return DB::transaction(function () use ($ctx, $e, $label, $by) {
            $row = TaxIncomeEstimate::query()->create($ctx->ownerAttributes() + [
                'tax_year_end' => $e->taxYearEnd, 'label' => mb_substr($label, 0, 120), 'regime' => $e->regime, 'figures' => $e->toArray(),
                'figures_hash' => $e->hash(), 'taxable_income_cents' => $e->taxableIncomeCents, 'tax_cents' => $e->taxCents,
                'rule_versions' => $e->ruleVersions, 'created_by_user_id' => $by->id,
            ]);
            FinanceAudit::record($ctx->ownerAttributes(), 'saved', 'tax_estimate', (int) $row->id, 'Income tax estimate '.$e->taxYearEnd, [
                'taxable_income_cents' => ['after' => $e->taxableIncomeCents], 'tax_cents' => ['after' => $e->taxCents],
            ], 'an estimate, not a return', null, $by->id);

            return $row;
        });
    }

    /**
     * Tax on a taxable income for the profile's regime. Returns [tax, label, detail, notModelled, warnings].
     *
     * @return array{0: int, 1: string, 2: array<string, mixed>, 3: list<string>, 4: list<string>}
     */
    public function tax(RuleSession $rules, TaxProfile $p, int $taxable, int $grossIncome, CarbonImmutable $end): array
    {
        $common = ['Capital gains tax', 'Dividends tax and foreign income', 'Tax paid already (provisional payments, employees\' tax)'];
        $warnings = [];

        if ($p->tax_regime === 'turnover_tax') {
            $taxpayer = $p->isCompanyLike() ? 'company' : 'individual';
            $t = $this->calculator->turnoverTax($rules, $grossIncome, $taxpayer, $end);
            if (! $t['within_limit']) {
                $warnings[] = 'Qualifying turnover of '.Money::format($grossIncome, 'ZAR').' is above the turnover tax limit of '.Money::format($t['limit_cents'], 'ZAR').': the business no longer qualifies for turnover tax.';
            }

            return [$t['tax_cents'], 'Turnover tax on income of '.Money::format($grossIncome, 'ZAR'), ['rule' => $t['rule']], array_merge($common, ['Qualifying turnover definition (professional services above 20 percent, personal service providers and labour brokers do not qualify)']), $warnings];
        }

        return match (true) {
            $p->legal_entity === 'trust' => (function () use ($rules, $taxable, $end, $common) {
                $t = $this->calculator->trust($rules, $taxable, $end);

                return [$t['tax_cents'], 'Trust (other than a special trust) at a flat '.($t['rate_bp'] / 100).' percent', ['rule' => $t['rule']], array_merge($common, ['Special trusts, distributions and the conduit principle']), []];
            })(),
            $p->legal_entity === 'sole_proprietor' => (function () use ($rules, $p, $taxable, $end, $common) {
                $band = $p->individual_age_band ?? 'under65';
                $t = $this->calculator->individual($rules, $taxable, $band, $end);

                return [$t['tax_cents'], 'Individual table for the tax year, less rebates ('.$band.')', ['before_rebates_cents' => $t['before_rebates_cents'], 'rebates_cents' => $t['rebates_cents'], 'threshold_cents' => $t['threshold_cents'], 'rule' => $t['rule']],
                    array_merge($common, ['Medical scheme fees credit and additional medical expenses', 'Retirement fund deductions', 'Interest exemption', 'Other income such as salary or rental']), []];
            })(),
            $p->legal_entity === 'partnership' => [0, 'A partnership is not taxed itself: its partners are', [], [], ['No tax is estimated for a partnership: each partner declares their share on their own return. The profit above is the amount to share.']],
            $p->legal_entity === 'non_profit' => [0, 'Non-profit organisation: exemption depends on SARS approval', [], [], ['No tax is estimated for a non-profit organisation: its exemption depends on SARS approval, which this tool cannot see.']],
            $p->tax_regime === 'sbc' => (function () use ($rules, $p, $taxable, $grossIncome, $end, $common) {
                $t = $this->calculator->sbc($rules, $taxable, $grossIncome, $p->shareholders_natural_persons, $end);
                if (! $t['eligible']) {
                    $std = $this->calculator->company($rules, $taxable, $end);

                    return [$std['tax_cents'], 'Company rate '.($std['rate_bp'] / 100).' percent: small business corporation tables do NOT apply', ['rule' => $std['rule'], 'sbc_reasons' => $t['reasons']], array_merge($common, ['Small business corporation conditions (personal service company, holding company)']), array_map(fn ($r) => 'Small business corporation tables do not apply: '.$r, $t['reasons'])];
                }

                return [$t['tax_cents'], 'Small business corporation tables', ['rule' => $t['rule']], array_merge($common, ['Whether the company is a personal service company or holds shares in other companies (conditions of section 12E)']), ['The small business corporation tables apply only if every condition is met, including some Dot.Finance cannot see (personal service company, holding company). Confirm them.']];
            })(),
            default => (function () use ($rules, $taxable, $end, $common) {
                $t = $this->calculator->company($rules, $taxable, $end);

                return [$t['tax_cents'], 'Company rate '.($t['rate_bp'] / 100).' percent', ['rule' => $t['rule']], array_merge($common, ['Special economic zone rates', 'Section 23M / 23N interest limits']), []];
            })(),
        };
    }

    /**
     * @param  Collection<int, object>  $expenseAccounts
     * @return list<array<string, mixed>>
     */
    private function flag($ctx, $expenseAccounts, CarbonImmutable $end): array
    {
        $decisions = TaxAdjustment::query()->forContext($ctx)->where('tax_year_end', $end->toDateString())->whereNull('voided_at')->whereNotNull('gl_account_id')->get()->groupBy('gl_account_id');
        $out = [];

        foreach ($expenseAccounts as $a) {
            if ($a->net_cents === 0) {
                continue;
            }
            foreach (ReviewPrompts::match($a->name) as $prompt) {
                $d = $decisions->get($a->gl_id);
                $out[] = [
                    'gl_id' => $a->gl_id, 'code' => $a->code, 'name' => $a->name, 'amount_cents' => $a->net_cents, 'key' => $prompt['key'], 'label' => $prompt['label'],
                    'weight' => $prompt['weight'], 'income_tax' => $prompt['income_tax'], 'vat' => $prompt['vat'], 'refs' => $prompt['refs'], 'suggested_kind' => $prompt['suggested_kind'],
                    'decision' => $d ? $d->pluck('kind')->unique()->values()->all() : null,
                    'basis' => TaxBasis::SUGGESTION,
                ];
            }
        }

        return $out;
    }
}
