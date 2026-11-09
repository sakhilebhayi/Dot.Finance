<?php

namespace App\Services\Accounting;

use InvalidArgumentException;

/**
 * One side of a journal entry. Amounts are base-currency minor units; exactly one of debit and
 * credit is non-zero. When the money was originally in another currency the original amount and
 * the rate (scaled by 10^8: base units per one unit of the original currency) travel with the line.
 */
final class PostingLine
{
    public function __construct(
        public string|int $account,          // GlAccount id or system key
        public int $debitCents = 0,
        public int $creditCents = 0,         // exactly one non-zero
        public ?string $txnCurrency = null,  // original currency when different from base
        public ?int $txnAmountCents = null,
        public ?int $fxRateE8 = null,
        public ?int $partyId = null,
        public ?int $cashAccountId = null,
        public ?int $categoryId = null,
        public ?int $taxCodeId = null,       // marks the line as VAT/tax-bearing for returns
        public ?string $dimension = null,    // e.g. 'project:12', 'department:3'
        public ?string $description = null,
    ) {
        if ($debitCents < 0 || $creditCents < 0 || ($debitCents === 0) === ($creditCents === 0)) {
            throw new InvalidArgumentException('A posting line has exactly one non-zero side: a positive debit or a positive credit.');
        }

        if (($txnCurrency === null) !== ($txnAmountCents === null) || ($txnAmountCents !== null && $txnAmountCents <= 0)) {
            throw new InvalidArgumentException('A line in another currency carries both the currency and a positive original amount.');
        }
    }

    public function isDebit(): bool
    {
        return $this->debitCents > 0;
    }

    public function amountCents(): int
    {
        return $this->debitCents > 0 ? $this->debitCents : $this->creditCents;
    }

    /** The same line with debit and credit swapped (for a reversal). */
    public function swapped(?string $description = null): self
    {
        return new self(
            $this->account, $this->creditCents, $this->debitCents, $this->txnCurrency, $this->txnAmountCents, $this->fxRateE8,
            $this->partyId, $this->cashAccountId, $this->categoryId, $this->taxCodeId, $this->dimension, $description ?? $this->description,
        );
    }
}
