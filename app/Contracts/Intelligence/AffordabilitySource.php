<?php

namespace App\Contracts\Intelligence;

use App\Support\Ledger\LedgerContext;

/**
 * "Can I afford X?" The default projects the recorded cash and the known recurring items over the next 30
 * days; Planning may bind a richer model (scenarios, budgets). Always an estimate.
 */
interface AffordabilitySource
{
    /**
     * @return array{currency: string, balance_cents: int, lowest_cents: int, lowest_date: ?string, days: int, commitments_cents: int, basis_note: string, href: ?string}|null null = no accounts to project from
     */
    public function projection(LedgerContext $context, int $days): ?array;
}
