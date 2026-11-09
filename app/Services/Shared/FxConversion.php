<?php

namespace App\Services\Shared;

/** The result of FxService::convert(): the converted minor units, their currency and the rate used. */
final readonly class FxConversion
{
    public function __construct(public int $amountMinor, public string $currency, public FxQuote $quote) {}
}
