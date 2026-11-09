<?php

namespace App\Contracts\Intelligence;

use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * The tax position and calendar. The default derives the VAT position from the VAT accounts of the general
 * ledger and knows no deadlines; the Tax module binds the real period and calendar.
 */
interface TaxPositionSource
{
    /** @return array{output_cents: int, input_cents: int, net_cents: int, currency: string, href: ?string, basis_note: string}|null */
    public function vatPosition(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): ?array;

    /** @return list<array{label: string, due_on: string, href: ?string, note: ?string}>|null null = no tax calendar for this ledger */
    public function deadlines(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): ?array;
}
