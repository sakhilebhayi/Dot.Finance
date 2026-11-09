<?php

namespace App\Services\Sales;

use App\Contracts\Reporting\OpenItem;
use App\Models\Accounting\LedgerSetting;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\PaymentAllocation;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Receivables open items for ageing and the cash projection, one per unpaid invoice or debit note with its real number and
 * due date, plus the customer credits as negative items. Values are in the ledger's base currency (each document at the rate
 * it was booked at), as the receivables account is kept. Reads the documents as they stand now.
 */
final class SalesOpenItems
{
    public function __construct(private readonly ReceivablesLedger $ledger) {}

    /** @return list<OpenItem> */
    public function items(LedgerContext $context): array
    {
        $currency = (string) (LedgerSetting::query()->forContext($context)->value('base_currency') ?? 'ZAR');
        $out = [];

        foreach ($this->ledger->openItems($context) as $item) {
            /** @var SalesDocument $doc */
            $doc = $item['document'];
            if ($item['open_base_cents'] <= 0) {
                continue;
            }
            $issued = $doc->issue_date->toDateString();
            $out[] = new OpenItem((int) $doc->party_id, (string) ($doc->party?->name ?? 'Customer'), (string) $doc->number, $issued, $doc->due_date?->toDateString() ?? $issued, (int) $item['open_base_cents'], $currency, fx_route('sales.documents.show', [$doc->id]));
        }

        foreach ($this->ledger->unappliedCredits($context) as $credit) {
            if ($credit['base_cents'] <= 0) {
                continue;
            }
            $date = $credit['date']->toDateString();
            $out[] = new OpenItem((int) $credit['party_id'], (string) ($credit['model']->party?->name ?? 'Customer'), (string) $credit['label'], $date, $date, -(int) $credit['base_cents'], $currency, null);
        }

        return $out;
    }

    /** @return array<int, array{settled: int, avg_days_late: int}> */
    public function history(LedgerContext $context): array
    {
        $paid = SalesDocument::query()->forContext($context)->whereIn('kind', ['tax_invoice', 'debit_note'])->where('status', 'paid')->whereNotNull('due_date')->get(['id', 'party_id', 'due_date']);
        if ($paid->isEmpty()) {
            return [];
        }

        $last = PaymentAllocation::query()->forContext($context)->where('allocatable_type', (new SalesDocument)->getMorphClass())->whereIn('allocatable_id', $paid->pluck('id'))
            ->whereNull('released_at')->join('payments as p', 'p.id', '=', 'payment_allocations.payment_id')->where('p.status', 'recorded')
            ->selectRaw('payment_allocations.allocatable_id as document_id, MAX(p.paid_on) as paid_on')->groupBy('payment_allocations.allocatable_id')->pluck('paid_on', 'document_id');

        $by = [];
        foreach ($paid as $doc) {
            if (! isset($last[$doc->id]) || $doc->party_id === null) {
                continue;
            }
            $late = (int) CarbonImmutable::parse($doc->due_date->toDateString())->diffInDays(CarbonImmutable::parse(substr((string) $last[$doc->id], 0, 10)), false);
            $by[(int) $doc->party_id]['n'] = ($by[(int) $doc->party_id]['n'] ?? 0) + 1;
            $by[(int) $doc->party_id]['days'] = ($by[(int) $doc->party_id]['days'] ?? 0) + $late;
        }

        return array_map(fn (array $r) => ['settled' => $r['n'], 'avg_days_late' => intdiv($r['days'], $r['n'])], $by);
    }
}
