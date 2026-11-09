<?php

namespace App\Contracts\Intelligence;

use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * What the business will have to pay soon. The default lists the recurring expenses the ledger already knows
 * about; the Purchases module may bind an implementation that adds approved bills by due date.
 */
interface PayablesSource
{
    /** @return list<array{label: string, due_on: string, amount_cents: int, currency: string, kind: string, href: ?string}>|null */
    public function upcoming(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): ?array;
}
