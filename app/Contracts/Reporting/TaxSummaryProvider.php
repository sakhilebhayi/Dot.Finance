<?php

namespace App\Contracts\Reporting;

use App\Support\Ledger\LedgerContext;

/**
 * What the Tax module knows that the journals alone do not: provisional-tax estimates, return status, obligations and
 * their due dates. The Tax summary report reads it when a module binds one; NullTaxSummaryProvider (the default) says
 * "nothing connected" and the report shows only what the ledger itself can calculate.
 *
 * Every item is labelled: 'calculated' (arithmetic on posted figures), 'estimate' (a projection or an assumption-driven
 * figure, never presented as final), or 'user_provided' (typed in by a person, for example a SARS reference).
 */
interface TaxSummaryProvider
{
    /**
     * @return array{source: string, items: list<array{label: string, amount_cents: ?int, kind: 'calculated'|'estimate'|'user_provided', due_on?: ?string, status?: ?string, note?: ?string, href?: ?string}>}|null
     */
    public function summary(LedgerContext $context, string $from, string $to): ?array;
}
