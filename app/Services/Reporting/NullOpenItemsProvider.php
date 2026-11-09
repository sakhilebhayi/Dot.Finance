<?php

namespace App\Services\Reporting;

use App\Contracts\Reporting\OpenItemsProvider;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/** "Nothing known": ageing and projections built on it are empty and say so. Bind it to switch the ledger-derived default off. */
final class NullOpenItemsProvider implements OpenItemsProvider
{
    public function openItems(LedgerContext $context, string $kind, CarbonImmutable $asOf): array
    {
        return [];
    }

    public function settlementHistory(LedgerContext $context, string $kind, CarbonImmutable $asOf): array
    {
        return [];
    }

    public function source(): string
    {
        return 'No source of open invoices or bills is connected.';
    }
}
