<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Everything the poster needs to write one entry. Source type and id say what the entry is about;
 * the version says which revision of that source this is (a repost is version + 1). Together with
 * the ledger they are the idempotency key.
 */
final class PostingRequest
{
    public const SOURCE_TYPES = [
        'transaction', 'transfer', 'opening_balance', 'import_batch', 'manual', 'invoice', 'credit_note', 'bill',
        'payment', 'payroll', 'period_close', 'fx_reval', 'reversal', 'adjustment',
    ];

    /** @param list<PostingLine> $lines */
    public function __construct(
        public CarbonImmutable $entryDate,
        public string $sourceType,   // transaction|transfer|opening_balance|import_batch|manual|invoice|credit_note|bill|payment|payroll|period_close|fx_reval|reversal|adjustment
        public int|string $sourceId,
        public int $sourceVersion,
        public string $memo,
        public array $lines,          // list<PostingLine>, >= 2, balanced
        public ?int $postedByUserId = null,
    ) {
        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException("Unknown journal source type: {$sourceType}");
        }

        if ($sourceVersion < 1) {
            throw new InvalidArgumentException('A source version starts at 1.');
        }
    }

    public function totalDebits(): int
    {
        return array_sum(array_map(fn (PostingLine $l) => $l->debitCents, $this->lines));
    }

    public function totalCredits(): int
    {
        return array_sum(array_map(fn (PostingLine $l) => $l->creditCents, $this->lines));
    }

    public function withVersion(int $version): self
    {
        return new self($this->entryDate, $this->sourceType, $this->sourceId, $version, $this->memo, $this->lines, $this->postedByUserId);
    }

    /**
     * What decides whether two postings are "the same money movement": the date and the
     * (account, side, amount, cash account, category, original currency amount) of every line,
     * order-independent. The memo and line descriptions are deliberately not part of it: editing
     * a description must not rewrite history.
     *
     * @param  array<string|int, int>  $systemKeyIds  system key => id, to compare keys with ids
     */
    public function signature(array $systemKeyIds = []): string
    {
        $parts = array_map(function (PostingLine $l) use ($systemKeyIds) {
            $account = is_string($l->account) ? ($systemKeyIds[$l->account] ?? $l->account) : $l->account;

            return implode(':', [$account, $l->debitCents, $l->creditCents, $l->cashAccountId, $l->categoryId, $l->txnCurrency, $l->txnAmountCents, $l->partyId, $l->dimension]);
        }, $this->lines);
        sort($parts);

        return $this->entryDate->toDateString().'|'.implode(',', $parts);
    }
}
