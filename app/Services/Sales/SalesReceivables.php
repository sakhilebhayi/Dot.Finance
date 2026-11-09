<?php

namespace App\Services\Sales;

use App\Contracts\Intelligence\DescribesReceivables;
use App\Contracts\Intelligence\ReceivablesSource;
use App\Models\Accounting\LedgerSetting;
use App\Models\Sales\SalesDocument;
use App\Services\Intelligence\Sources\GlReceivables;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Who owes the business money, from the invoices themselves (open balance, due date, number) once the ledger issues
 * invoices; before that the general ledger's receivables account answers, as it did. Amounts are in the ledger's base
 * currency, the way the receivables account is kept.
 */
final class SalesReceivables implements DescribesReceivables, ReceivablesSource
{
    public function __construct(private readonly ReceivablesLedger $ledger, private readonly GlReceivables $fallback) {}

    public function owedByCustomers(LedgerContext $context): ?array
    {
        if (! $this->hasInvoices($context)) {
            return $this->fallback->owedByCustomers($context);
        }

        $currency = $this->currency($context);
        $rows = [];
        foreach ($this->ledger->openItems($context) as $item) {
            /** @var SalesDocument $doc */
            $doc = $item['document'];
            $key = (int) $doc->party_id;
            $rows[$key] ??= ['party' => (string) ($doc->party?->name ?? 'Customer'), 'owed_cents' => 0, 'currency' => $currency, 'oldest' => null, 'href' => fx_route('sales.customers.show', [$key])];
            $rows[$key]['owed_cents'] += (int) $item['open_base_cents'];
            $issued = $doc->issue_date?->toDateString();
            $rows[$key]['oldest'] = $rows[$key]['oldest'] === null || ($issued !== null && $issued < $rows[$key]['oldest']) ? $issued : $rows[$key]['oldest'];
        }

        // what a customer has paid ahead or been credited is netted off what that customer owes, as on the statement
        foreach ($this->ledger->unappliedCredits($context) as $credit) {
            $key = (int) $credit['party_id'];
            if (isset($rows[$key])) {
                $rows[$key]['owed_cents'] -= (int) $credit['base_cents'];
            }
        }

        $rows = array_values(array_filter($rows, fn (array $r) => $r['owed_cents'] > 0));
        usort($rows, fn ($a, $b) => $b['owed_cents'] <=> $a['owed_cents']);

        return $rows;
    }

    /**
     * The one figure: the same summary the Invoices, Customers and Receivables pages use. `source` says plainly what it was
     * worked out from; `gap_cents` is how far the receivables account of the general ledger stands from it, with the reasons.
     */
    public function position(LedgerContext $context): ?array
    {
        if (! $this->hasInvoices($context)) {
            return null;
        }

        $summary = $this->ledger->summary($context);
        $rec = $summary['reconcile'];
        $reasons = [];
        foreach (collect($rec['items'])->groupBy('source') as $source => $rows) {
            $reasons[] = $rows->count().' '.($rows->count() === 1 ? 'entry' : 'entries').' of '.str_replace('_', ' ', (string) $source).' type posted to receivables without a matching record in Sales, '.Money::format((int) $rows->sum('cents'), $this->currency($context));
        }
        if ($rec['remainder_cents'] !== 0) {
            $reasons[] = Money::format(abs((int) $rec['remainder_cents']), $this->currency($context)).' that no entry explains';
        }

        return [
            'source' => 'your invoices, credit notes and payments recorded in Sales', 'total_cents' => (int) $summary['net_base_cents'], 'credit_cents' => (int) $summary['credit_base_cents'],
            'currency' => $this->currency($context), 'books_cents' => (int) $rec['ledger_cents'], 'gap_cents' => (int) $rec['difference_cents'], 'agrees' => (bool) $rec['ok'], 'reconciling' => $reasons,
        ];
    }

    public function overdueInvoices(LedgerContext $context, CarbonImmutable $asOf): ?array
    {
        if (! $this->hasInvoices($context)) {
            return null;
        }

        $out = [];
        foreach ($this->ledger->openItems($context) as $item) {
            /** @var SalesDocument $doc */
            $doc = $item['document'];
            if ($doc->due_date === null || $doc->due_date->toDateString() >= $asOf->toDateString() || $item['open_cents'] <= 0) {
                continue;
            }

            $out[] = [
                'number' => (string) $doc->number,
                'party' => (string) ($doc->party?->name ?? 'Customer'),
                'due_on' => $doc->due_date->toDateString(),
                'days_overdue' => (int) CarbonImmutable::parse($doc->due_date->toDateString())->diffInDays($asOf),
                'open_cents' => (int) $item['open_cents'],
                'currency' => (string) $item['currency'],
                'href' => fx_route('sales.documents.show', [$doc->id]),
            ];
        }

        usort($out, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

        return $out;
    }

    private function hasInvoices(LedgerContext $context): bool
    {
        return SalesDocument::query()->forContext($context)->whereIn('kind', ['tax_invoice', 'debit_note'])->whereNotNull('sealed_at')->exists();
    }

    private function currency(LedgerContext $context): string
    {
        return (string) (LedgerSetting::query()->forContext($context)->value('base_currency') ?? 'ZAR');
    }
}
