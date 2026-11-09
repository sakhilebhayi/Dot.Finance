<?php

namespace App\Services\Reporting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Purchases\Bill;
use App\Models\Sales\SalesApplication;
use App\Models\Shared\PaymentAllocation;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Cash-basis recognition of invoices and bills.
 *
 * METHOD. Entries that are not documents (a transaction, a manual journal, a payroll run) already move on the day the
 * money moved, so they are taken as posted. An entry whose source_type is one of the configured documents (an invoice,
 * a bill) is taken instead as the PAID SHARE of its profit-and-loss lines, recognised on each payment date:
 * the share through a date is (live allocations paid on or before that date, capped at the document's gross amount)
 * divided by the gross amount carried on the document's receivable (invoice) or payable (bill) line. Each line's
 * recognised amount through a date is round(line x share), so the recognised amounts add up to the line exactly when the
 * document is paid in full, and a period's figure is the difference between two dates (no rounding drift).
 *
 * LIMITS, stated on every report that uses it:
 *  - credit notes and other adjustments are recognised when they are issued, not netted against the document they
 *    relate to (the ledger does not link them), so a credited, partly paid invoice can differ from a cash-basis ledger;
 *  - money received on account (not allocated to a document) recognises nothing until it is allocated;
 *  - a payment reversed or an allocation released after the fact changes past periods (the method reads current state);
 *  - accruals, prepayments and depreciation are not adjusted: they are taken as posted;
 *  - VAT is a balance-sheet item in the ledger, so a cash-basis profit and loss is not a VAT return.
 */
class CashBasis
{
    /** @return list<string> the source_type values treated as documents */
    public function documentTypes(): array
    {
        return array_keys(config('reporting.cash_basis.documents', []));
    }

    /**
     * Every recognition event up to $to: ['entry_id', 'line_id', 'gl_account_id', 'date', 'net' (debit minus credit), 'party_id', 'category_id', 'dimension'].
     *
     * @return list<array<string, mixed>>
     */
    public function events(LedgerContext $context, string $to): array
    {
        $events = [];
        $plAccounts = GlAccount::query()->forContext($context)->whereIn('class', ['income', 'expense'])->pluck('id')->flip();
        $controlIds = [];

        foreach (config('reporting.cash_basis.documents', []) as $type => $spec) {
            $control = GlAccount::query()->forContext($context)->where('system_key', $spec['control'])->value('id');
            $controlIds[$type] = $control !== null ? (int) $control : null;
        }

        // Allocations by (type, document id): [paid_on => cents], live ones only, and only payments that stand.
        $paid = [];
        $allocations = PaymentAllocation::query()->forContext($context)
            ->join('payments as p', 'p.id', '=', 'payment_allocations.payment_id')
            ->whereNull('payment_allocations.released_at')->where('p.status', 'recorded')->where('p.paid_on', '<=', $to)
            ->get(['payment_allocations.allocatable_type', 'payment_allocations.allocatable_id', 'payment_allocations.amount_cents', 'p.paid_on']);

        foreach ($allocations as $a) {
            $docType = $this->documentTypeFor((string) $a->allocatable_type);
            if ($docType === null) {
                continue;
            }
            $date = substr((string) $a->paid_on, 0, 10);
            $key = $this->journalSourceId((string) $a->allocatable_type, (string) $a->allocatable_id);
            $paid[$docType][$key][$date] = ($paid[$docType][$key][$date] ?? 0) + (int) $a->amount_cents;
        }

        // A credit note applied to an invoice (Sales) or linked to a bill (Purchases) settles that document as surely as money does: it is
        // the document's own credit, recognised on the day it was applied, and the credit note's own lines (taken as posted, on its date)
        // then net the two off. Without it a credited, paid invoice would be counted short by the credit (R-44 integration, D-1207).
        foreach ($this->creditSettlements($context, $to) as [$docType, $key, $date, $amount]) {
            $paid[$docType][$key][$date] = ($paid[$docType][$key][$date] ?? 0) + $amount;
        }

        foreach ($this->documentTypes() as $type) {
            $entries = JournalEntry::query()->forContext($context)->where('source_type', $type)->where('status', 'posted')->where('entry_date', '<=', $to)->get(['id', 'source_id', 'entry_date']);
            if ($entries->isEmpty()) {
                continue;
            }

            foreach ($entries->chunk(400) as $chunk) {
                $lines = JournalLine::query()->forContext($context)->whereIn('journal_entry_id', $chunk->pluck('id'))->orderBy('id')->get()->groupBy('journal_entry_id');

                foreach ($chunk as $entry) {
                    $entryLines = $lines->get($entry->id, collect());
                    $control = $controlIds[$type] ?? null;
                    $side = config("reporting.cash_basis.documents.{$type}.side") === 'credit' ? 'credit_cents' : 'debit_cents';
                    $gross = $control === null ? 0 : (int) $entryLines->where('gl_account_id', $control)->sum($side);
                    $schedule = $paid[$type][(string) $entry->source_id] ?? [];
                    ksort($schedule);

                    foreach ($entryLines as $line) {
                        if (! $plAccounts->has($line->gl_account_id)) {
                            continue;
                        }

                        $net = (int) $line->debit_cents - (int) $line->credit_cents;
                        if ($net === 0) {
                            continue;
                        }

                        $base = [
                            'entry_id' => $entry->id, 'line_id' => $line->id, 'gl_account_id' => (int) $line->gl_account_id,
                            'party_id' => $line->party_id, 'category_id' => $line->category_id, 'dimension' => $line->dimension,
                        ];

                        // A document with no receivable or payable line carries nothing to be paid: take it as posted.
                        if ($gross <= 0) {
                            $events[] = $base + ['date' => $entry->entry_date->toDateString(), 'net' => $net];

                            continue;
                        }

                        $cum = 0;
                        $recognised = 0;
                        foreach ($schedule as $date => $amount) {
                            $cum = min($gross, $cum + $amount);
                            $through = Money::mulDivRound($net, $cum, $gross);
                            if ($through !== $recognised) {
                                $events[] = $base + ['date' => $date, 'net' => $through - $recognised];
                                $recognised = $through;
                            }
                        }
                    }
                }
            }
        }

        usort($events, fn ($a, $b) => [$a['date'], $a['line_id'], $a['net']] <=> [$b['date'], $b['line_id'], $b['net']]);

        return $events;
    }

    /**
     * Credits that settle documents: [document type, journal source id, date, amount in base currency].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private function creditSettlements(LedgerContext $context, string $to): array
    {
        $out = [];

        if (class_exists(SalesApplication::class) && Schema::hasTable('sales_applications')) {
            foreach (SalesApplication::query()->forContext($context)->where('kind', 'credit')->whereNull('released_at')->where('applied_on', '<=', $to)->get(['target_document_id', 'target_base_cents', 'applied_on']) as $a) {
                $out[] = ['invoice', (string) $a->target_document_id, $a->applied_on->toDateString(), (int) $a->target_base_cents];
            }
        }

        if (class_exists(Bill::class) && Schema::hasTable('bills')) {
            foreach (Bill::query()->forContext($context)->where('kind', 'credit_note')->whereNotNull('credit_for_bill_id')->whereIn('status', Bill::POSTED)->where('bill_date', '<=', $to)->get(['credit_for_bill_id', 'total_cents', 'base_total_cents', 'bill_date']) as $c) {
                $out[] = ['bill', (string) $c->credit_for_bill_id, $c->bill_date->toDateString(), (int) ($c->base_total_cents ?? $c->total_cents)];
            }
        }

        return $out;
    }

    /**
     * The source_id the journal entry of a payable document carries. A bill's is its id; an expense claim is posted as a 'bill'
     * entry whose source_id is "claim-<id>" (ClaimWorkflow), so a payment allocated to the claim finds its entry (R-44 integration).
     */
    private function journalSourceId(string $allocatableType, string $id): string
    {
        return $allocatableType === 'expense_claim' ? 'claim-'.$id : $id;
    }

    /** 'invoice' / 'bill' for an allocation type such as "stub_invoice" or "App\Models\Sales\Invoice"; null when it is not a document type. */
    public function documentTypeFor(string $allocatableType): ?string
    {
        $name = Str::snake(class_basename($allocatableType));

        foreach (config('reporting.cash_basis.documents', []) as $type => $spec) {
            if (in_array($name, $spec['allocatable'], true)) {
                return $type;
            }
        }

        return null;
    }
}
