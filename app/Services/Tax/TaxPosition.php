<?php

namespace App\Services\Tax;

use App\Contracts\Intelligence\TaxPositionSource;
use App\Models\Accounting\LedgerSetting;
use App\Models\Tax\TaxProfile;
use App\Services\Intelligence\Sources\GlTaxPosition;
use App\Services\Tax\Exceptions\TaxException;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;

/**
 * The tax position for the assistant. For a ledger with a VAT-registered tax profile the VAT figure is the VAT201
 * builder's own (the same tagged journal lines, the same cancellations), so what the assistant says equals what the return
 * will show; for anything else it falls back to the VAT accounts of the general ledger. Deadlines come from the stored
 * calendar, and are null (not "none") while the ledger has no calendar.
 */
final class TaxPosition implements TaxPositionSource
{
    public function __construct(
        private readonly Vat201Builder $vat,
        private readonly GlTaxPosition $fallback,
        private readonly TaxDeadlines $deadlines,
    ) {}

    public function vatPosition(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $profile = TaxProfile::query()->forContext($context)->first();
        if ($profile === null || ! $profile->vat_registered) {
            return $this->fallback->vatPosition($context, $from, $to);
        }

        try {
            $computed = $this->vat->build($context, $from, $to);
        } catch (NoValidTaxRule|TaxException) {
            return $this->fallback->vatPosition($context, $from, $to);
        }

        if ($computed->outputTaxCents === 0 && $computed->inputTaxCents === 0) {
            return null;
        }

        return [
            'output_cents' => $computed->outputTaxCents,
            'input_cents' => $computed->inputTaxCents,
            'net_cents' => $computed->outputTaxCents - $computed->inputTaxCents,
            'currency' => (string) (LedgerSetting::query()->forContext($context)->value('base_currency') ?? 'ZAR'),
            'href' => fx_route('tax.vat.index'),
            'basis_note' => 'Calculated by the same rules as the VAT201 on the Tax screens (tagged journal lines for the dates asked). It is not a return: your filing period and any adjustments are on the VAT screens.',
        ];
    }

    public function deadlines(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        return $this->deadlines->upcoming($context, $from, $to);
    }
}
