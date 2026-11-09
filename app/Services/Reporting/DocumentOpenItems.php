<?php

namespace App\Services\Reporting;

use App\Contracts\Reporting\OpenItemsProvider;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Sales\SalesDocument;
use App\Services\Purchases\PurchaseOpenItems;
use App\Services\Sales\SalesOpenItems;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Open items for ageing and the cash projection, from the documents when the ledger has them: Sales' invoices and debit notes for
 * receivables (real numbers and due dates), Purchases' bills and expense claims for payables. Document balances are as they stand
 * today, so a report "as at" an earlier date, and a ledger with no documents of that kind, are answered from the ledger's own
 * postings (GlOpenItemsProvider), which can be read at any date. The "ties to the control account" badge on the reports keeps
 * both honest.
 */
final class DocumentOpenItems implements OpenItemsProvider
{
    public function __construct(
        private readonly GlOpenItemsProvider $gl,
        private readonly SalesOpenItems $sales,
        private readonly PurchaseOpenItems $purchases,
    ) {}

    public function openItems(LedgerContext $context, string $kind, CarbonImmutable $asOf): array
    {
        if (! $this->fromDocuments($context, $kind, $asOf)) {
            return $this->gl->openItems($context, $kind, $asOf);
        }

        return $kind === 'payable' ? $this->purchases->items($context, $asOf) : $this->sales->items($context);
    }

    public function settlementHistory(LedgerContext $context, string $kind, CarbonImmutable $asOf): array
    {
        $documents = $this->hasDocuments($context, $kind) ? ($kind === 'payable' ? $this->purchases->history($context) : $this->sales->history($context)) : [];

        // parties the documents know nothing about still get what the postings say
        return $documents + $this->gl->settlementHistory($context, $kind, $asOf);
    }

    public function source(): string
    {
        return 'Open invoices, debit notes, bills, expense claims, credit notes and money on account as they stand today, with their real numbers and due dates; for an earlier date, or a ledger without such documents, derived from the receivable and payable accounts, oldest amount settled first.';
    }

    private function fromDocuments(LedgerContext $context, string $kind, CarbonImmutable $asOf): bool
    {
        return $asOf->toDateString() >= CarbonImmutable::today()->toDateString() && $this->hasDocuments($context, $kind);
    }

    private function hasDocuments(LedgerContext $context, string $kind): bool
    {
        if ($kind === 'payable') {
            return LedgerContext::run($context, fn () => Bill::query()->whereIn('status', Bill::POSTED)->exists() || ExpenseClaim::query()->whereIn('status', Bill::POSTED)->exists());
        }

        return SalesDocument::query()->forContext($context)->whereIn('kind', ['tax_invoice', 'debit_note'])->whereNotNull('sealed_at')->exists();
    }
}
