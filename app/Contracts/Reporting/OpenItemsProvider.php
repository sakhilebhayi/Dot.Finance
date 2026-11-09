<?php

namespace App\Contracts\Reporting;

use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * What is still owed to the business (receivable) or by it (payable) on a date, one item per unpaid document, with the
 * date it falls due; and how each customer or supplier has historically settled.
 *
 * Ageing and the cash projection read through this interface. The default binding (GlOpenItemsProvider) derives the
 * items from the ledger's receivable and payable accounts, oldest first, so reports work as soon as the books carry
 * those postings. The Sales and Purchases modules may bind an implementation that reads their documents (real due dates,
 * invoice numbers, links); NullOpenItemsProvider is the "nothing known" implementation.
 *
 * Implementations must be confined to the ledger they are handed and must not write.
 */
interface OpenItemsProvider
{
    /**
     * @param  'receivable'|'payable'  $kind
     * @return list<OpenItem>
     */
    public function openItems(LedgerContext $context, string $kind, CarbonImmutable $asOf): array;

    /**
     * How settled documents were paid, per party: how many were settled and the average days after the due date they
     * were settled (negative = early). Parties with no settled document are absent.
     *
     * @param  'receivable'|'payable'  $kind
     * @return array<int, array{settled: int, avg_days_late: int}>
     */
    public function settlementHistory(LedgerContext $context, string $kind, CarbonImmutable $asOf): array;

    /** A short statement of where the figures come from, shown on the report. */
    public function source(): string;
}
