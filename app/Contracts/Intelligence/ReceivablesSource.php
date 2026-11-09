<?php

namespace App\Contracts\Intelligence;

use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Who owes the business money. The default reads the receivables account of the general ledger (by customer); the
 * Sales module may bind a richer implementation that knows invoices and due dates:
 *
 *   $this->app->bind(\App\Contracts\Intelligence\ReceivablesSource::class, SalesReceivables::class);
 *
 * Return null (not an empty list) when the ledger has no data to answer from, so the assistant can say so.
 */
interface ReceivablesSource
{
    /** @return list<array{party: string, owed_cents: int, currency: string, oldest: ?string, href: ?string}>|null */
    public function owedByCustomers(LedgerContext $context): ?array;

    /** @return list<array{number: string, party: string, due_on: string, days_overdue: int, open_cents: int, currency: string, href: ?string}>|null */
    public function overdueInvoices(LedgerContext $context, CarbonImmutable $asOf): ?array;
}
