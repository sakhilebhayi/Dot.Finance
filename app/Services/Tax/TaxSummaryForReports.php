<?php

namespace App\Services\Tax;

use App\Contracts\Reporting\TaxSummaryProvider;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxProfile;
use App\Models\Tax\TaxProvisionalPayment;
use App\Models\Tax\TaxReturn;
use App\Support\Ledger\LedgerContext;

/**
 * What the Tax summary report adds to the ledger's own figures: the filing state of each return and the deadlines that fall
 * in the period, with what is calculated, what is an estimate and what a person typed in kept apart. Null while the ledger
 * has no tax profile and no calendar (then the report says nothing is connected, rather than showing an empty list).
 */
final class TaxSummaryForReports implements TaxSummaryProvider
{
    public function summary(LedgerContext $context, string $from, string $to): ?array
    {
        $hasProfile = TaxProfile::query()->forContext($context)->exists();
        $deadlines = TaxDeadline::query()->forContext($context)->where('due_date', '>=', $from)->where('due_date', '<=', $to)->orderBy('due_date')->get();

        if (! $hasProfile && $deadlines->isEmpty()) {
            return null;
        }

        $items = [];

        foreach ($deadlines as $d) {
            $return = $d->tax_return_id !== null ? TaxReturn::query()->forContext($context)->find($d->tax_return_id) : null;
            $items[] = [
                'label' => $d->label().($d->period_label ? ' for '.$d->period_label : ''),
                'amount_cents' => $return !== null && ! $return->isDraft() ? (int) $return->payable_cents : null,
                'kind' => $return !== null && ! $return->isDraft() ? 'calculated' : 'estimate',
                'due_on' => $d->due_date->toDateString(),
                'status' => $d->displayStatus(),
                'note' => $d->adjustment_note,
                'href' => fx_route('tax.calendar.show', [$d->id]),
            ];

            if ($return !== null && $return->sars_reference) {
                $items[] = ['label' => 'SARS reference for '.($d->period_label ?: $d->label()), 'amount_cents' => null, 'kind' => 'user_provided', 'status' => $return->statusLabel(), 'note' => $return->sars_reference, 'href' => fx_route('tax.returns.show', [$return->id])];
            }
        }

        $provisional = TaxProvisionalPayment::query()->forContext($context)->where('due_date', '>=', $from)->where('due_date', '<=', $to)->orderBy('due_date')->get();
        foreach ($provisional as $p) {
            $items[] = [
                'label' => 'Provisional tax, payment '.$p->period,
                'amount_cents' => (int) $p->amount_due_cents,
                'kind' => 'estimate',
                'due_on' => $p->due_date->toDateString(),
                'status' => $p->paid_cents >= $p->amount_due_cents && $p->amount_due_cents > 0 ? 'paid' : null,
                'note' => 'Rests on the taxable-income estimate entered on the Provisional tax screen.',
                'href' => fx_route('tax.provisional.index'),
            ];
        }

        return ['source' => 'tax calendar, returns and estimates held on the Tax screens', 'items' => $items];
    }
}
