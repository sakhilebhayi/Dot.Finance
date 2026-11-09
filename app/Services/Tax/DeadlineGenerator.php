<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxProfile;
use App\Services\Shared\Tax\ResolvedRule;
use App\Services\Tax\Dto\DueDate;
use App\Services\Tax\Jurisdiction\JurisdictionRegistry;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;

/**
 * Builds the tax calendar from the rules and the profile. Idempotent: a deadline is identified by
 * (obligation, period), so running it again refreshes the dates of an untouched row and never duplicates one. A row a
 * person has moved on (prepared, submitted, paid, waived) is never changed by it.
 *
 * Only obligations the ObligationEngine says APPLY are generated. Periods whose due date fell before the profile was
 * completed are not generated (they are handled before the person started here); a person who disagrees can still
 * prepare that period by hand.
 */
final class DeadlineGenerator
{
    public function __construct(
        private readonly TaxRules $rules,
        private readonly ObligationEngine $engine,
        private readonly VatPeriods $vatPeriods,
        private readonly JurisdictionRegistry $jurisdictions,
    ) {}

    /**
     * @return array{created: int, updated: int, unchanged: int, skipped: list<string>}
     */
    public function generate(LedgerContext $ctx, TaxProfile $profile, ?CarbonImmutable $asOf = null, int $monthsAhead = 14): array
    {
        $asOf ??= CarbonImmutable::today();
        $pack = $this->jurisdictions->get($profile->jurisdiction);
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => []];

        if (! $pack->isConfigured()) {
            $result['skipped'][] = "{$pack->name()} is not configured: no deadlines were generated.";

            return $result;
        }

        $rules = $this->rules->session($ctx, $profile->jurisdiction);
        $dueDates = new DueDates(new BusinessCalendar($profile->jurisdiction));
        $since = $profile->wizard_completed_at ? CarbonImmutable::parse($profile->wizard_completed_at)->startOfMonth() : $asOf->startOfMonth();
        $until = $asOf->addMonths($monthsAhead);
        $applies = collect($this->engine->assess($ctx, $profile, $asOf))->filter(fn ($a) => $a->generatesDeadlines)->keyBy('key');

        $emit = function (string $obligation, CarbonImmutable $start, CarbonImmutable $end, string $label, DueDate $due, RuleSession $s) use ($ctx, $since, $until, &$result) {
            if ($due->due->lt($since) || $due->due->gt($until)) {
                return;
            }
            $this->upsert($ctx, $obligation, $start, $end, $label, $due, $s, $result);
        };

        if ($applies->has('vat')) {
            $this->vat($profile, $rules, $dueDates, $since, $until, $asOf, $emit, $result);
        }
        if ($applies->has('emp201')) {
            $this->employer($profile, $rules, $dueDates, $since, $until, $asOf, $emit);
        }
        if ($applies->has('provisional')) {
            $this->provisional($profile, $rules, $dueDates, $asOf, $emit, $pack);
        }
        if ($applies->has('itr14')) {
            $this->itr14($profile, $rules, $dueDates, $asOf, $emit);
        }
        if ($applies->has('itr12')) {
            $this->itr12($profile, $rules, $asOf, $emit, $result);
        }
        if ($applies->has('coida')) {
            $this->coida($rules, $dueDates, $asOf, $emit, $result);
        }

        return $result;
    }

    /** Whether a person has already acted on the row (the generator must leave it alone). */
    private function settled(TaxDeadline $d): bool
    {
        return $d->status !== 'open' || $d->tax_return_id !== null;
    }

    private function upsert(LedgerContext $ctx, string $obligation, CarbonImmutable $start, CarbonImmutable $end, string $label, DueDate $due, RuleSession $rules, array &$result): void
    {
        $row = TaxDeadline::query()->forContext($ctx)->where('obligation', $obligation)->where('period_start', $start->toDateString())->where('period_end', $end->toDateString())->first();

        $attributes = [
            'period_label' => $label,
            'base_due_date' => $due->base->toDateString(),
            'due_date' => $due->due->toDateString(),
            'payment_due_date' => $due->paymentDue?->toDateString(),
            'adjustment_note' => $due->note() !== null ? mb_substr($due->note(), 0, 500) : null,
            'rule_versions' => $rules->versions(),
            'confidence' => $due->confidence,
            'generated_at' => now(),
        ];

        if ($row === null) {
            TaxDeadline::query()->create($ctx->ownerAttributes() + $attributes + ['obligation' => $obligation, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'status' => 'open']);
            $result['created']++;

            return;
        }

        if ($this->settled($row)) {
            $result['unchanged']++;

            return;
        }

        $row->fill($attributes);
        if ($row->isDirty(['base_due_date', 'due_date', 'payment_due_date', 'adjustment_note', 'confidence', 'period_label'])) {
            $row->save();
            $result['updated']++;
        } else {
            $result['unchanged']++;
        }
    }

    private function vat(TaxProfile $p, RuleSession $rules, DueDates $dueDates, CarbonImmutable $since, CarbonImmutable $until, CarbonImmutable $asOf, callable $emit, array &$result): void
    {
        if (! $p->vat_category) {
            $result['skipped'][] = 'VAT: no period category in the profile, so no VAT deadlines were generated.';

            return;
        }

        $channel = $p->vat_filing_channel ?? $rules->rule('vat.due_date.default_channel', $asOf)->text();
        $from = $since->subMonths(7);
        if ($p->vat_registered_on && CarbonImmutable::parse($p->vat_registered_on->toDateString())->gt($from)) {
            $from = CarbonImmutable::parse($p->vat_registered_on->toDateString());
        }

        foreach ($this->vatPeriods->between($rules, $from, $until, $p->vat_category, $p->financial_year_end_month) as $period) {
            $due = $dueDates->vat($rules, $period['end'], $channel);
            $emit('vat201', $period['start'], $period['end'], $this->vatPeriods->label($period, $p->vat_category), $due, $rules);
        }
    }

    private function employer(TaxProfile $p, RuleSession $rules, DueDates $dueDates, CarbonImmutable $since, CarbonImmutable $until, CarbonImmutable $asOf, callable $emit): void
    {
        $month = $since->subMonth()->startOfMonth();
        while ($month->lte($until)) {
            $end = $month->endOfMonth()->startOfDay();
            $emit('emp201', $month, $end, $month->format('F Y'), $dueDates->emp201($rules, $end), $rules);
            $month = $month->addMonth();
        }

        // EMP501: the tax year runs 1 March to the end of February (an employer's reconciliation year).
        foreach ([$asOf->year - 1, $asOf->year, $asOf->year + 1] as $y) {
            $interimStart = CarbonImmutable::create($y, 3, 1);
            $interimEnd = CarbonImmutable::create($y, 8, 31);
            $rule = $rules->find('emp501.interim_'.$y, $interimEnd) ?? $rules->find('emp501.interim_window', $interimEnd);
            if ($rule !== null) {
                $due = $this->windowEnd($rule, $y, 'window_end');
                $emit('emp501_interim', $interimStart, $interimEnd, "Interim reconciliation, 1 March to 31 August {$y}", $dueDates->fixed($due, $rule->confidence, $rule->key === 'emp501.interim_window' ? 'The dates repeat the 2026 notice; SARS publishes each year\'s window. Confirm it.' : 'From the SARS notice for this window.'), $rules);
            }

            $annualEnd = CarbonImmutable::create($y + 1, 2, 1)->endOfMonth()->startOfDay();
            $annual = $rules->find('emp501.annual_window', $annualEnd);
            if ($annual !== null) {
                $spec = $annual->json();
                [$m, $d] = array_map('intval', explode('-', $spec['window_end_md']));
                $emit('emp501_annual', $interimStart, $annualEnd, "Annual reconciliation, 1 March {$y} to end February ".($y + 1), $dueDates->fixed(CarbonImmutable::create($y + 1, $m, $d), $annual->confidence, 'The window is inferred from the standing calendar: SARS publishes the dates each year. Confirm it.'), $rules);
            }
        }
    }

    private function provisional(TaxProfile $p, RuleSession $rules, DueDates $dueDates, CarbonImmutable $asOf, callable $emit, $pack): void
    {
        foreach ([$asOf->subYear(), $asOf, $asOf->addYear()] as $probe) {
            $year = $pack->taxYear($p->legal_entity, $p->financial_year_end_month, $probe);
            $label = 'year ending '.$year['end']->format('j F Y');
            foreach ([1, 2, 3] as $period) {
                $due = $dueDates->provisional($rules, $year['start'], $year['end'], $period);
                $periodStart = match ($period) { 1 => $year['start'], 2 => $year['start'], 3 => $year['end']->addDay() };
                $periodEnd = match ($period) { 1 => $year['start']->addMonthsNoOverflow(5)->endOfMonth()->startOfDay(), 2 => $year['end'], 3 => $due->base };
                $emit("irp6_{$period}", $periodStart, $periodEnd, ['', 'First', 'Second', 'Third (optional top-up)'][$period]." provisional payment, {$label}", $due, $rules);
            }
        }
    }

    private function itr14(TaxProfile $p, RuleSession $rules, DueDates $dueDates, CarbonImmutable $asOf, callable $emit): void
    {
        foreach ([$asOf->subYear(), $asOf] as $probe) {
            $fye = CarbonImmutable::create($probe->year, $p->financial_year_end_month, 1)->endOfMonth()->startOfDay();
            if ($fye->gt($asOf->addYear())) {
                continue;
            }
            $emit('itr14', $fye->subYear()->addDay(), $fye, 'Company return for the year ending '.$fye->format('j F Y'), $dueDates->itr14($rules, $fye), $rules);
        }
    }

    private function itr12(TaxProfile $p, RuleSession $rules, CarbonImmutable $asOf, callable $emit, array &$result): void
    {
        $rule = $rules->find('itr12.filing_season', $asOf, '2026');
        if ($rule === null) {
            $result['skipped'][] = 'ITR12: no filing-season dates are on record.';

            return;
        }
        $spec = $rule->json();
        $provisionalLike = $p->provisional_taxpayer || $p->legal_entity === 'trust';
        $base = CarbonImmutable::parse($provisionalLike ? $spec['provisional_and_trusts_due'] : $spec['non_provisional_due']);
        $end = CarbonImmutable::parse($spec['tax_year_end']);
        $due = new DueDate($base, $base, null, ['SARS announces the filing season each year; this is the 2026 season.'], $rule->confidence);
        $emit('itr12', $end->subYear()->addDay(), $end, 'Personal return for the tax year ending '.$end->format('j F Y'), $due, $rules);
    }

    private function coida(RuleSession $rules, DueDates $dueDates, CarbonImmutable $asOf, callable $emit, array &$result): void
    {
        foreach ([$asOf->year, $asOf->year + 1] as $y) {
            $rule = $rules->find('coida.roe_window', CarbonImmutable::create($y, 6, 30));
            if ($rule === null) {
                $result['skipped'][] = 'COIDA: no return-of-earnings window is on record.';

                return;
            }
            [$m, $d] = array_map('intval', explode('-', $rule->json()['window_end_md']));
            $due = $dueDates->fixed(CarbonImmutable::create($y, $m, $d), $rule->confidence, 'The window repeats the 2026 one; the Compensation Fund announces each year\'s. A reminder only: COIDA is not a SARS tax.');
            $emit('coida_roe', CarbonImmutable::create($y - 1, 3, 1), CarbonImmutable::create($y, 2, 1)->endOfMonth()->startOfDay(), "Return of earnings for the year ending February {$y}", $due, $rules);
        }
    }

    private function windowEnd(ResolvedRule $rule, int $year, string $key): CarbonImmutable
    {
        $spec = $rule->json();
        if (isset($spec['window_end'])) {
            return CarbonImmutable::parse($spec['window_end']);
        }
        [$m, $d] = array_map('intval', explode('-', $spec[$key.'_md']));

        return CarbonImmutable::create($year, $m, $d);
    }
}
