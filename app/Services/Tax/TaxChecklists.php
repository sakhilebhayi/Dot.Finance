<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxAdjustment;
use App\Models\Tax\TaxChecklistTick;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxDocument;
use App\Models\Tax\TaxIncomeEstimate;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Tax\TaxProfile;
use App\Models\Tax\TaxProvisionalPayment;
use App\Models\Tax\TaxReturn;
use App\Models\User;
use App\Services\Tax\Exceptions\TaxException;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * A checklist for each obligation and period. Items that can be checked from real rows are ticked by the system ("auto") and say so;
 * the rest are ticked by a person, with a note, and the tick is kept. Nothing here files anything.
 */
final class TaxChecklists
{
    /**
     * @return list<array{key: string, label: string, done: bool, auto: bool, detail: ?string, href: ?string}>
     */
    public function for(LedgerContext $ctx, TaxDeadline $deadline, ?TaxProfile $profile): array
    {
        $ticks = TaxChecklistTick::query()->forContext($ctx)->where('tax_deadline_id', $deadline->id)->get()->keyBy('item_key');
        $return = $deadline->tax_return_id ? TaxReturn::query()->forContext($ctx)->find($deadline->tax_return_id) : null;
        $start = CarbonImmutable::parse($deadline->period_start->toDateString());
        $end = CarbonImmutable::parse($deadline->period_end->toDateString());

        $auto = fn (string $key, string $label, bool $done, ?string $detail = null, ?string $href = null) => ['key' => $key, 'label' => $label, 'done' => $done, 'auto' => true, 'detail' => $detail, 'href' => $href];
        $manual = fn (string $key, string $label, ?string $detail = null) => ['key' => $key, 'label' => $label, 'done' => $ticks->has($key), 'auto' => false, 'detail' => $ticks->get($key)?->note ?: $detail, 'href' => null];

        $items = [];

        switch ($deadline->obligation) {
            case 'vat201':
                $items[] = $auto('profile', 'VAT number and period category are in the tax profile', (bool) ($profile?->vat_number && $profile?->vat_category), null, route('tax.profile.edit'));
                $items[] = $auto('period_over', 'The period has ended', $end->lt(CarbonImmutable::today()), $end->format('j F Y'));
                $items[] = $auto('review', 'The VAT review queue is clear', $return?->isPrepared() || $return?->isSubmitted() || $this->vatClear($ctx, $start, $end), 'Fix or accept each flagged posting.', route('tax.vat.review', ['from' => $start->toDateString(), 'to' => $end->toDateString()]));
                $items[] = $manual('invoices_held', 'Valid tax invoices are held for every input VAT claim', 'A claim needs a valid tax invoice, debit or credit note, or a customs document.');
                $items[] = $auto('prepared', 'The return is prepared (figures frozen)', $return !== null && ! $return->isDraft(), null, route('tax.vat.index'));
                $items[] = $manual('filed_efiling', 'I filed the VAT201 on SARS eFiling (or at a branch) myself', 'Dot.Finance cannot file for you.');
                $items[] = $auto('submission_recorded', 'The submission is recorded here with the SARS reference', (bool) $return?->isSubmitted());
                $items[] = $auto('payment_recorded', 'The payment is recorded', $return?->status === 'paid' || ((int) ($return?->payable_cents ?? 1) <= 0 && $return?->isSubmitted()), 'A nil or refund return has no payment.');
                $items[] = $auto('proof_kept', 'Proof of submission is kept in the vault', (bool) ($return?->submission_proof_attachment_id) || TaxDocument::query()->forContext($ctx)->where('tax_deadline_id', $deadline->id)->exists(), null, route('tax.documents.index'));
                break;

            case 'emp201':
                $run = TaxPayrollRun::query()->forContext($ctx)->where('period_month', $start->toDateString())->first();
                $items[] = $auto('payroll_summary', 'Payroll tax summary is recorded for the month', $run !== null, null, route('tax.payroll.index'));
                $items[] = $auto('payroll_journal', 'The payroll journal is posted to the books', (bool) $run?->isPosted(), null, $run ? route('tax.payroll.show', $run) : null);
                $items[] = $auto('prepared', 'The EMP201 is prepared (figures frozen)', $return !== null && ! $return->isDraft());
                $items[] = $manual('filed_efiling', 'I filed the EMP201 on SARS eFiling myself');
                $items[] = $auto('submission_recorded', 'The submission is recorded here with the SARS reference', (bool) $return?->isSubmitted());
                $items[] = $auto('payment_recorded', 'The payment is recorded', $return?->status === 'paid');
                break;

            case 'emp501_interim':
            case 'emp501_annual':
                $months = TaxPayrollRun::query()->forContext($ctx)->whereBetween('period_month', [$start->toDateString(), $end->toDateString()])->count();
                $submitted = TaxReturn::query()->forContext($ctx)->where('kind', 'emp201')->whereIn('status', ['submitted', 'paid'])->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])->count();
                $expected = (int) $start->diffInMonths($end->addDay());
                $items[] = $auto('payroll_months', 'A payroll summary is recorded for every month of the period', $months >= $expected, "{$months} of {$expected} months recorded.", route('tax.payroll.index'));
                $items[] = $auto('emp201s', 'Every month\'s EMP201 is recorded as submitted', $submitted >= $expected, "{$submitted} of {$expected} recorded.", route('tax.emp201.index'));
                $items[] = $manual('certificates', 'The IRP5 / IT3(a) certificates match the payroll', 'The pre-populated certificate values must reconcile to the EMP201 payments.');
                $items[] = $manual('tax_numbers', 'Every employee has a valid income tax number', 'SARS enforces valid numbers strictly.');
                $items[] = $manual('differences', 'Differences between the EMP201 payments and the certificates are explained');
                $items[] = $manual('submitted_efile', 'I submitted the EMP501 reconciliation myself (e@syFile Employer or eFiling)');
                $items[] = $manual('proof_kept', 'Proof of submission is kept in the vault');
                break;

            case 'irp6_1':
            case 'irp6_2':
            case 'irp6_3':
                $period = (int) substr($deadline->obligation, -1);
                $row = TaxProvisionalPayment::query()->forContext($ctx)->where('period', $period)->whereBetween('due_date', [$deadline->due_date->subDays(7)->toDateString(), $deadline->due_date->addDays(7)->toDateString()])->first();
                $items[] = $auto('estimate', 'Your taxable income estimate for this period is entered', $row?->estimate_taxable_income_cents !== null, 'The estimate is yours; the schedule only does the arithmetic.', route('tax.provisional.index'));
                $items[] = $manual('basic_amount', 'The basic amount (last assessed taxable income) is checked', 'The estimate may not be lower than it (with the 8 percent rule after 18 months).');
                $items[] = $manual('filed_irp6', 'I submitted the IRP6 on SARS eFiling myself');
                $items[] = $auto('payment', 'The payment is recorded', (bool) $row?->paid_on);
                break;

            case 'itr14':
            case 'itr12':
                $year = $end->toDateString();
                $items[] = $auto('estimate_saved', 'An income tax estimate is saved for the year', TaxIncomeEstimate::query()->forContext($ctx)->where('tax_year_end', $year)->exists(), null, route('tax.income.index'));
                $items[] = $auto('adjustments', 'Review prompts and adjustments are worked through', TaxAdjustment::query()->forContext($ctx)->where('tax_year_end', $year)->whereNull('voided_at')->exists(), 'At least one reviewed or adjusted item is recorded.', route('tax.income.index'));
                $items[] = $manual('afs', 'The annual financial statements are final');
                $items[] = $manual('provisional_reconciled', 'Provisional payments are reconciled to SARS\'s statement of account');
                $items[] = $manual('docs', 'Supporting documents (invoices, certificates, logbooks) are in the vault');
                $items[] = $manual('filed_return', 'I submitted the return on SARS eFiling myself');
                break;

            case 'coida_roe':
                $items[] = $manual('earnings', 'Total earnings per employee for the year are totalled (capped at the maximum)');
                $items[] = $manual('filed_roe', 'I submitted the return of earnings (W.As.8) to the Compensation Fund myself');
                $items[] = $manual('assessment_paid', 'The assessment is paid (needed for a letter of good standing)');
                break;
        }

        return $items;
    }

    public function tick(LedgerContext $ctx, TaxDeadline $deadline, string $key, ?string $note, User $by): TaxChecklistTick
    {
        if (! in_array($key, array_column(array_filter($this->for($ctx, $deadline, null), fn ($i) => ! $i['auto']), 'key'), true)) {
            throw new TaxException('That item is ticked by the system, not by hand.');
        }

        return TaxChecklistTick::query()->firstOrCreate(
            ['tax_deadline_id' => $deadline->id, 'item_key' => $key] + $ctx->ownerAttributes(),
            ['note' => $note !== null ? mb_substr(trim($note), 0, 255) : null, 'ticked_by_user_id' => $by->id, 'ticked_at' => now()],
        );
    }

    public function untick(LedgerContext $ctx, TaxDeadline $deadline, string $key): void
    {
        TaxChecklistTick::query()->forContext($ctx)->where('tax_deadline_id', $deadline->id)->where('item_key', $key)->delete();
    }

    private function vatClear(LedgerContext $ctx, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $booksOnly = app(Vat201Builder::class)->build($ctx, $start, $end, []);

        return collect(app(VatReviewQueue::class)->forPeriod($ctx, $start, $end, $booksOnly))->where('severity', 'error')->where('resolved', false)->isEmpty();
    }
}
