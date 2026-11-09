<?php

namespace App\Services\Tax;

use App\Contracts\Planning\PayrollCommitmentsProvider;
use App\Contracts\Planning\TaxObligationsProvider;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Tax\TaxProvisionalPayment;
use App\Models\Tax\TaxReturn;
use App\Services\Tax\Exceptions\TaxException;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;

/**
 * The tax payments and payroll levies the cash projection should know about, from the Tax module's own rows. Nothing is
 * invented: a payment appears only when it is a stored deadline with an amount worked out from the books (a VAT201), from
 * a person's provisional-tax estimate or from a captured payroll summary (an EMP201).
 *
 *   TaxObligationsProvider      VAT201 payments (the prepared return's figure, else the builder's figure for the period,
 *                               labelled estimate) and provisional tax instalments still unpaid (estimate: it rests on the
 *                               estimate someone entered)
 *   PayrollCommitmentsProvider  the EMP201 payment (PAYE + UIF + SDL less ETI) for a month whose payroll summary exists
 *                               (calculated when Dot.HR sent it, user provided when typed in)
 *
 * Salaries themselves are not projected: nothing records a future pay run, and the projection must not guess one.
 */
final class TaxObligationsForPlanning implements PayrollCommitmentsProvider, TaxObligationsProvider
{
    public function __construct(private readonly Vat201Builder $vat) {}

    public function obligations(LedgerContext $context, string $from, string $to): array
    {
        $out = [];

        $deadlines = TaxDeadline::query()->forContext($context)->whereIn('status', ['open', 'prepared'])
            ->whereIn('obligation', ['vat201', 'irp6_1', 'irp6_2', 'irp6_3'])
            ->get()->filter(fn (TaxDeadline $d) => $this->payOn($d)->between(CarbonImmutable::parse($from), CarbonImmutable::parse($to)));

        foreach ($deadlines as $d) {
            if ($d->obligation === 'vat201') {
                [$cents, $kind] = $this->vatAmount($context, $d);
                if ($cents > 0) {
                    $out[] = ['date' => $this->payOn($d)->toDateString(), 'label' => 'VAT201 payment'.($d->period_label ? ' for '.$d->period_label : ''), 'amount_cents' => $cents, 'kind' => $kind, 'href' => fx_route('tax.calendar.show', [$d->id])];
                }

                continue;
            }

            $row = TaxProvisionalPayment::query()->forContext($context)->where('period', (int) substr($d->obligation, -1))->where('due_date', $d->due_date->toDateString())->first();
            $owed = $row !== null ? $row->amount_due_cents - $row->paid_cents : 0;
            if ($owed > 0) {
                $out[] = ['date' => $this->payOn($d)->toDateString(), 'label' => $d->label(), 'amount_cents' => $owed, 'kind' => 'estimate', 'href' => fx_route('tax.provisional.index')];
            }
        }

        usort($out, fn ($a, $b) => [$a['date'], $a['label']] <=> [$b['date'], $b['label']]);

        return $out;
    }

    public function commitments(LedgerContext $context, string $from, string $to): array
    {
        $out = [];
        $deadlines = TaxDeadline::query()->forContext($context)->whereIn('status', ['open', 'prepared'])->where('obligation', 'emp201')
            ->get()->filter(fn (TaxDeadline $d) => $this->payOn($d)->between(CarbonImmutable::parse($from), CarbonImmutable::parse($to)));

        foreach ($deadlines as $d) {
            $run = TaxPayrollRun::query()->forContext($context)->where('period_month', $d->period_start->toDateString())->first();
            if ($run === null || $run->emp201LiabilityCents() <= 0) {
                continue;
            }

            $out[] = [
                'date' => $this->payOn($d)->toDateString(),
                'label' => 'PAYE, UIF and SDL (EMP201) for '.$d->period_start->format('F Y'),
                'amount_cents' => $run->emp201LiabilityCents(),
                'kind' => $run->source === 'hr_import' ? 'calculated' : 'user_provided',
            ];
        }

        usort($out, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $out;
    }

    private function payOn(TaxDeadline $d): CarbonImmutable
    {
        return CarbonImmutable::parse(($d->payment_due_date ?? $d->due_date)->toDateString());
    }

    /** @return array{0: int, 1: 'calculated'|'estimate'} */
    private function vatAmount(LedgerContext $context, TaxDeadline $d): array
    {
        if ($d->tax_return_id !== null) {
            $return = TaxReturn::query()->forContext($context)->find($d->tax_return_id);
            if ($return !== null && ! $return->isDraft()) {
                return [max(0, (int) $return->payable_cents - (int) $return->paid_cents), 'calculated'];
            }
        }

        try {
            $computed = $this->vat->build($context, $d->period_start, $d->period_end);
        } catch (NoValidTaxRule|TaxException) {
            return [0, 'estimate'];
        }

        return [max(0, $computed->payableCents), 'estimate'];
    }
}
