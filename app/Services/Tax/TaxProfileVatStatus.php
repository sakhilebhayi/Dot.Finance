<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxProfile;
use App\Services\Sales\Support\ProfileVatStatus;
use App\Services\Sales\Support\VatStatus;
use App\Support\Ledger\LedgerContext;

/**
 * Whether the business charges VAT, as Sales asks. Once the Tax profile exists it is the single place the answer lives, so
 * the invoice, the bill and the VAT201 can never disagree; before that the Sales business profile answers, as it did.
 */
final class TaxProfileVatStatus implements VatStatus
{
    public function __construct(private readonly ProfileVatStatus $salesProfile) {}

    public function isRegistered(LedgerContext $context): bool
    {
        $profile = TaxProfile::query()->forContext($context)->first();

        return $profile !== null ? (bool) $profile->vat_registered : $this->salesProfile->isRegistered($context);
    }

    public function jurisdiction(LedgerContext $context): string
    {
        $profile = TaxProfile::query()->forContext($context)->first();

        return $profile !== null ? ($profile->jurisdiction ?: 'ZA') : $this->salesProfile->jurisdiction($context);
    }

    public function vatNumber(LedgerContext $context): ?string
    {
        $profile = TaxProfile::query()->forContext($context)->first();

        return $profile !== null && $profile->vat_registered && filled($profile->vat_number) ? $profile->vat_number : $this->salesProfile->vatNumber($context);
    }
}
