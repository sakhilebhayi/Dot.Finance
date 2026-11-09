<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxCorrespondence;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxDocument;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Tax\TaxProfile;
use App\Models\Tax\TaxReturn;
use App\Services\Purchases\MissingDocuments;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * What is missing, per obligation and period, worked out from real rows. "Three supplier invoices are missing their supporting
 * document" comes from the VAT review queue (input VAT with no file attached) unless the Purchases module's own
 * MissingDocuments service exists, in which case its count is shown as well. Each item says where to fix it.
 */
final class MissingRecords
{
    public function __construct(
        private readonly TaxReturnService $returns,
        private readonly VatReviewQueue $queue,
        private readonly Vat201Builder $vat,
        private readonly MissingDocuments $purchases,
    ) {}

    /**
     * @return list<array{key: string, label: string, count: int, detail: string, basis: string, href: ?string}>
     */
    public function forDeadline(LedgerContext $ctx, TaxDeadline $deadline, ?TaxProfile $profile): array
    {
        $from = CarbonImmutable::parse($deadline->period_start->toDateString());
        $to = CarbonImmutable::parse($deadline->period_end->toDateString());
        $items = [];

        if ($deadline->obligation === 'vat201') {
            $booksOnly = $this->vat->build($ctx, $from, $to, []);
            $issues = collect($this->queue->forPeriod($ctx, $from, $to, $booksOnly))->reject(fn ($i) => $i['resolved']);

            $noDoc = $issues->where('code', 'input_vat_no_document')->count();
            if ($noDoc > 0) {
                $items[] = ['key' => 'supplier_documents', 'label' => $noDoc.' supplier invoice'.($noDoc === 1 ? '' : 's').' missing the supporting document', 'count' => $noDoc,
                    'detail' => 'Input VAT is claimed on entries that have no file attached. A claim needs a valid tax invoice.', 'basis' => TaxBasis::CALCULATED, 'href' => route('tax.vat.review', ['from' => $from->toDateString(), 'to' => $to->toDateString()])];
            }
            $noVat = $issues->where('code', 'input_vat_no_supplier_vat_number')->count();
            if ($noVat > 0) {
                $items[] = ['key' => 'supplier_vat_numbers', 'label' => $noVat.' supplier'.($noVat === 1 ? '' : ' claim').' without a VAT number on file', 'count' => $noVat,
                    'detail' => 'Add the supplier\'s VAT number from their tax invoice.', 'basis' => TaxBasis::CALCULATED, 'href' => route('tax.vat.review', ['from' => $from->toDateString(), 'to' => $to->toDateString()])];
            }
            $errors = $issues->where('severity', 'error')->count();
            if ($errors > 0) {
                $items[] = ['key' => 'review_errors', 'label' => $errors.' VAT posting'.($errors === 1 ? '' : 's').' to fix or accept', 'count' => $errors, 'detail' => 'These block the return until fixed or accepted with a note.', 'basis' => TaxBasis::CALCULATED, 'href' => route('tax.vat.review', ['from' => $from->toDateString(), 'to' => $to->toDateString()])];
            }
            $items = array_merge($items, $this->purchasesModule($ctx, $from, $to));
        }

        if ($deadline->obligation === 'emp201') {
            $run = TaxPayrollRun::query()->forContext($ctx)->where('period_month', $from->toDateString())->first();
            if ($run === null) {
                $items[] = ['key' => 'payroll_summary', 'label' => 'No payroll tax summary for '.$from->format('F Y'), 'count' => 1, 'detail' => 'Record it, or import it from Dot.HR.', 'basis' => TaxBasis::CALCULATED, 'href' => route('tax.payroll.index')];
            } elseif (! $run->isPosted()) {
                $items[] = ['key' => 'payroll_journal', 'label' => 'The payroll journal for '.$from->format('F Y').' is not posted', 'count' => 1, 'detail' => 'Confirm the suggested journal so the payroll liabilities in the books match the summary.', 'basis' => TaxBasis::CALCULATED, 'href' => route('tax.payroll.show', $run)];
            }
        }

        $return = $deadline->tax_return_id ? TaxReturn::query()->forContext($ctx)->find($deadline->tax_return_id) : null;
        $hasProofDocument = TaxDocument::query()->forContext($ctx)->where('tax_deadline_id', $deadline->id)->whereIn('category', ['vat_return_proof', 'emp_reconciliation'])->exists();
        if ($return?->isSubmitted() && $return->submission_proof_attachment_id === null && ! $hasProofDocument) {
            $items[] = ['key' => 'submission_proof', 'label' => 'No proof of submission kept', 'count' => 1, 'detail' => 'Keep SARS\'s acknowledgement for five years.', 'basis' => TaxBasis::CALCULATED, 'href' => route('tax.documents.index')];
        }
        if ($return?->status === 'paid' && $return->payment_proof_attachment_id === null) {
            $items[] = ['key' => 'payment_proof', 'label' => 'No proof of payment kept', 'count' => 1, 'detail' => 'Attach the payment confirmation.', 'basis' => TaxBasis::CALCULATED, 'href' => route('tax.documents.index')];
        }

        return $items;
    }

    /** Correspondence waiting for a reply. */
    public function openCorrespondence(LedgerContext $ctx)
    {
        return TaxCorrespondence::query()->forContext($ctx)->where('status', 'open')->orderBy('response_due_on')->get();
    }

    /**
     * The Purchases module's own list of records with no document (MissingDocuments::forLedger), restricted to the period, so the
     * VAT201 checklist says how many bills, claim receipts and expenses dated in it have nothing attached. Some of these also appear
     * as "supplier invoices missing the supporting document" above (the ones that carry input VAT); the detail says how many.
     *
     * @return list<array{key: string, label: string, count: int, detail: string, basis: string, href: ?string}>
     */
    private function purchasesModule(LedgerContext $ctx, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $inPeriod = $this->purchases->forLedger($ctx)->filter(fn (array $i) => $i['date'] >= $from->toDateString() && $i['date'] <= $to->toDateString());
        $count = $inPeriod->count();
        if ($count === 0) {
            return [];
        }

        $withVat = $inPeriod->where('counts_for_tax', true)->count();

        return [['key' => 'purchases_missing', 'label' => $count.' bill'.($count === 1 ? '' : 's').', receipt'.($count === 1 ? '' : 's').' or expense'.($count === 1 ? '' : 's').' in the period with no document attached (Purchases)', 'count' => $count,
            'detail' => $withVat > 0 ? "{$withVat} of them carry input VAT. The list, with where to attach each file, is in Purchases." : 'None carries input VAT. The list, with where to attach each file, is in Purchases.', 'basis' => TaxBasis::CALCULATED, 'href' => fx_route('purchases.documents.index')]];
    }
}
