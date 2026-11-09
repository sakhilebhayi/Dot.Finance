<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxReturn;

/**
 * How a prepared return reaches SARS. Only ManualFilingAdapter exists: SARS publishes no open API, and third-party
 * submission is only possible through its Independent Software Vendor (ISV) programme, which needs an application to SARS by
 * the product owner and a per-business access key activated on the business's own eFiling profile. That adapter is NOT
 * implemented; see docs/R44_TAX.md (owner steps). Nothing in Dot.Finance may call a method that pretends to file.
 */
interface FilingAdapter
{
    public function name(): string;

    /** True only for an adapter that really transmits to SARS. */
    public function canSubmitElectronically(): bool;

    /**
     * The steps a person follows to file this return themselves.
     *
     * @return list<array{step: string, detail: string}>
     */
    public function instructions(TaxReturn $return): array;

    /** @throws \App\Services\Tax\Exceptions\NotSubmittableElectronically when the adapter cannot transmit */
    public function submit(TaxReturn $return): never;
}
