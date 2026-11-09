<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LedgerSetting;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\Accounting\Exceptions\UnpostableSource;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the journals in step with the cash ledger. Every operation is "make the GL say what the cash
 * ledger says about this source": compute what the source should post, compare it with the live entry,
 * and post / repost / reverse / do nothing. Because the operation is idempotent it is the same code for
 * a request that just wrote a row (dual-write), the nightly sweeper, and the backfill.
 *
 * Callers invoke it explicitly inside the DB transaction that wrote the source row (decision D-10);
 * there is deliberately no model observer, so a write that bypasses it is found by the sweeper and the
 * cash tie-out rather than hidden.
 *
 * The public dual-write methods do nothing unless accounting is enabled for the ledger, and they let a
 * period-closed refusal propagate (the caller's transaction rolls back, so the cash row is never written
 * into a locked month). A row that cannot be posted for lack of an exchange rate is logged and left for
 * the sweeper: it must not stop a person recording their own money.
 */
class GlSync
{
    /** @var array<string, ?LedgerSetting> */
    private array $settings = [];

    /** @var array<string, PostingMap> */
    private array $maps = [];

    public function __construct(
        private readonly Chart $chart,
        private readonly JournalPoster $poster,
        private readonly PostingRules $rules,
    ) {}

    public function flush(): void
    {
        $this->settings = [];
        $this->maps = [];
        $this->chart->flush();
    }

    public function settings(LedgerContext $context): ?LedgerSetting
    {
        return $this->settings[$context->key()] ??= LedgerSetting::query()->forContext($context)->first();
    }

    public function isEnabled(LedgerContext $context): bool
    {
        return $this->settings($context)?->isEnabled() ?? false;
    }

    // ── dual-write entry points (no-ops while accounting is off) ──────────────

    /** After a transaction was created, updated or deleted. */
    public function transaction(Transaction $transaction): void
    {
        $context = $transaction->ledgerContext();

        if (! $this->isEnabled($context)) {
            return;
        }

        $this->guarded(fn () => $this->syncTransaction($context, $transaction));
    }

    /** After a transfer group changed (both legs created, or a leg deleted). */
    public function transferGroup(LedgerContext $context, string $group): void
    {
        if (! $this->isEnabled($context)) {
            return;
        }

        $this->guarded(fn () => $this->syncTransfer($context, $group));
    }

    /** After an account's opening balance or date changed: its opening entry and every row the cut-off may have moved. */
    public function account(Account $account): void
    {
        $context = $account->ledgerContext();

        if (! $this->isEnabled($context)) {
            return;
        }

        $this->guarded(fn () => $this->syncAccount($context, $account));
    }

    // ── strict operations (used by the backfill, the sweeper and the dual-write wrappers) ─

    /** @return string posted|reposted|reversed|unchanged|skipped */
    public function syncTransaction(LedgerContext $context, Transaction $transaction): string
    {
        if ($transaction->type === 'transfer') {
            return $transaction->transfer_group !== null ? $this->syncTransfer($context, $transaction->transfer_group) : 'skipped';
        }

        $map = $this->mapFor($context, $transaction->account_id, $transaction->category_id);
        $desired = $this->countsFor($transaction) ? $this->rules->transaction($context, $transaction, $map) : null;

        return $this->ensure($context, 'transaction', (string) $transaction->id, $desired, 'The transaction was edited or deleted');
    }

    /** @return string posted|reposted|reversed|unchanged|skipped */
    public function syncTransfer(LedgerContext $context, string $group): string
    {
        // Every leg that still exists, deleted ones included: the pair's own amounts value the movement even when one
        // leg no longer counts. Which legs count is decided separately.
        $legs = Transaction::query()->forContext($context)->withTrashed()->where('transfer_group', $group)->with('account')->get();

        $out = $legs->firstWhere('transfer_direction', 'out');
        $in = $legs->firstWhere('transfer_direction', 'in');
        $outCounts = $out !== null && $this->countsFor($out);
        $inCounts = $in !== null && $this->countsFor($in);

        $desired = null;
        if ($outCounts || $inCounts) {
            $map = $this->mapFor($context, array_filter([$out?->account_id, $in?->account_id]));
            $desired = $this->rules->transfer($context, $out, $in, $group, $map, $outCounts, $inCounts);
        }

        return $this->ensure($context, 'transfer', $group, $desired, 'The transfer was deleted or changed');
    }

    /** @return string posted|reposted|reversed|unchanged|skipped */
    public function syncOpening(LedgerContext $context, Account $account): string
    {
        $map = $this->mapFor($context, $account->id);
        $date = $this->openingDate($context, $account);
        $desired = $account->trashed() ? null : $this->rules->openingBalance($context, $account, $date, $map);

        return $this->ensure($context, 'opening_balance', (string) $account->id, $desired, 'The opening balance changed');
    }

    /** Opening entry plus every transaction of the account (the cut-off may have moved). */
    public function syncAccount(LedgerContext $context, Account $account): void
    {
        $this->syncOpening($context, $account);

        $groups = [];
        Transaction::query()->forContext($context)->withTrashed()->where('account_id', $account->id)->with('account')->orderBy('id')->each(function (Transaction $t) use ($context, &$groups) {
            if ($t->type === 'transfer') {
                if ($t->transfer_group !== null) {
                    $groups[$t->transfer_group] = true;
                }

                return;
            }
            $this->syncTransaction($context, $t);
        });

        foreach (array_keys($groups) as $group) {
            $this->syncTransfer($context, $group);
        }
    }

    /**
     * The date the opening entry is dated: the account's own opening date; for an account with none (legacy data,
     * where every row counts) the ledger's accounting start date, fixed when accounting was prepared, so it does not
     * wander as later rows arrive.
     */
    public function openingDate(LedgerContext $context, Account $account): CarbonImmutable
    {
        if ($account->opening_balance_date !== null) {
            return CarbonImmutable::parse($account->opening_balance_date->toDateString());
        }

        $start = $this->settings($context)?->accounting_start_date?->toDateString();

        return CarbonImmutable::parse($start ?? now()->toDateString());
    }

    /** Whether a cash-ledger row affects the books: live and dated on or after its account's opening date. */
    public function countsFor(Transaction $transaction): bool
    {
        if ($transaction->trashed()) {
            return false;
        }

        $opening = $transaction->account?->opening_balance_date;

        return PostingRules::skipReason($transaction, $opening !== null ? CarbonImmutable::parse($opening->toDateString()) : null) === null;
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** Post, repost, reverse or leave alone so that the live entry of a source matches $desired. */
    private function ensure(LedgerContext $context, string $type, string $sourceId, ?PostingRequest $desired, string $reason): string
    {
        $live = $this->poster->liveEntry($context, $type, $sourceId);

        if ($desired === null) {
            if ($live === null) {
                return 'unchanged';
            }

            $this->poster->reverse($live, $reason);

            return 'reversed';
        }

        $maxVersion = (int) JournalEntry::query()->forContext($context)->where('source_type', $type)->where('source_id', $sourceId)->max('source_version');

        if ($live === null) {
            $this->poster->post($desired->withVersion($maxVersion + 1));

            return 'posted';
        }

        if ($this->signatureOf($live) === $desired->signature()) {
            return 'unchanged';
        }

        $this->poster->repost($type, $sourceId, $desired, $reason);

        return 'reposted';
    }

    private function signatureOf(JournalEntry $entry): string
    {
        $parts = $entry->lines()->get()->map(fn ($l) => implode(':', [
            $l->gl_account_id, $l->debit_cents, $l->credit_cents, $l->cash_account_id, $l->category_id, $l->txn_currency, $l->txn_amount_cents, $l->party_id, $l->dimension,
        ]))->all();
        sort($parts);

        return $entry->entry_date->toDateString().'|'.implode(',', $parts);
    }

    /**
     * The ledger's PostingMap, making sure the cash accounts and category named are mapped (a new account or
     * category is given a GL account of its own on first use) and the chart is complete.
     *
     * @param  int|list<int>  $accountIds
     */
    private function mapFor(LedgerContext $context, int|array|null $accountIds, ?int $categoryId = null): PostingMap
    {
        $settings = $this->settings($context) ?? throw new Exceptions\AccountingNotReady('This ledger has no accounting settings yet.');
        $map = $this->maps[$context->key()] ?? null;
        $rebuild = $map === null;

        foreach ((array) $accountIds as $id) {
            if ($id !== null && ! isset($map?->cash[(int) $id])) {
                $account = Account::query()->forContext($context)->withTrashed()->find((int) $id);
                if ($account !== null) {
                    $this->chart->mapAccount($account);
                    $rebuild = true;
                }
            }
        }

        if ($categoryId !== null && ! isset($map?->categories[$categoryId])) {
            $category = Category::query()->forContext($context)->withTrashed()->find($categoryId);
            if ($category !== null) {
                $this->chart->mapCategory($category);
                $rebuild = true;
            }
        }

        if ($rebuild) {
            $this->maps[$context->key()] = $map = $this->chart->map($context, $settings->base_currency);
        }

        return $map;
    }

    /** Run a dual-write step: an unpostable source is logged for the sweeper, everything else propagates. */
    private function guarded(callable $step): void
    {
        try {
            $step();
        } catch (UnpostableSource $e) {
            Log::warning('GL posting deferred: '.$e->getMessage());
        }
    }
}
