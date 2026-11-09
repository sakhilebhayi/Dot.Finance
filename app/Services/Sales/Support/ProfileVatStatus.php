<?php

namespace App\Services\Sales\Support;

use App\Services\Sales\SalesProfiles;
use App\Support\Ledger\LedgerContext;

/** The default VatStatus: what the Sales business profile says. */
final class ProfileVatStatus implements VatStatus
{
    public function __construct(private readonly SalesProfiles $profiles) {}

    public function isRegistered(LedgerContext $context): bool
    {
        return (bool) $this->profiles->for($context)->vat_registered;
    }

    public function jurisdiction(LedgerContext $context): string
    {
        return $this->profiles->for($context)->tax_jurisdiction ?: 'ZA';
    }

    public function vatNumber(LedgerContext $context): ?string
    {
        return $this->profiles->for($context)->vat_number;
    }
}
