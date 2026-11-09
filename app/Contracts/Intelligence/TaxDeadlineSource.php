<?php

namespace App\Contracts\Intelligence;

use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/** Tax deadlines inside a window, for the deadline notifications. The Tax module binds the real calendar. */
interface TaxDeadlineSource
{
    /** @return list<array{key: string, label: string, due_on: string, href: ?string}> */
    public function dueBetween(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): array;
}
