<?php

namespace App\Services\Sales\Support;

use App\Support\Ledger\LedgerContext;

/**
 * Whether the business charges VAT, and under which jurisdiction. Sales reads it from the business profile; when the
 * Tax module's financial profile exists it can bind its own implementation of this interface and Sales follows.
 */
interface VatStatus
{
    public function isRegistered(LedgerContext $context): bool;

    public function jurisdiction(LedgerContext $context): string;

    public function vatNumber(LedgerContext $context): ?string;
}
