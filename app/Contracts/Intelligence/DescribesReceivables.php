<?php

namespace App\Contracts\Intelligence;

use App\Support\Ledger\LedgerContext;

/**
 * Optional companion to ReceivablesSource: a source that knows where its figure came from and how it stands against the
 * general ledger says so, so the Assistant names the real source and never claims one it did not use.
 */
interface DescribesReceivables
{
    /**
     * @return array{source: string, total_cents: int, credit_cents: int, currency: string, books_cents: int, gap_cents: int, agrees: bool, reconciling: list<string>}|null null when
     *                                                                                                                                                                      the source is not the document sub-ledger for this ledger
     */
    public function position(LedgerContext $context): ?array;
}
