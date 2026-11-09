<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\Accounting\Exceptions\UnpostableSource;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\DB;

/**
 * The safety net behind explicit posting (decision D-10). `pending()` finds cash-ledger rows whose
 * journals are missing or stale: a transaction nobody posted, a deleted one whose entry is still live,
 * a transfer or opening balance likewise. `run()` posts them. Because posting is idempotent by source
 * key, running it twice, or while a request is posting the same row, is harmless.
 *
 * Content drift (a posted row later edited around the app) shows as a failed cash tie-out for that
 * account; `run()` re-syncs every account whose tie-out fails.
 */
class GlSweeper
{
    public function __construct(private readonly GlSync $sync, private readonly GlQuery $query) {}

    /**
     * @return array{transactions: list<int>, transfers: list<string>, openings: list<int>}
     */
    public function pending(LedgerContext $context): array
    {
        $owned = fn ($q, string $alias) => $context->isCompany()
            ? $q->where("{$alias}.team_id", $context->teamId)
            : $q->where("{$alias}.user_id", $context->userId)->whereNull("{$alias}.team_id");

        $liveEntry = fn (string $type, string $sourceExpr) => fn ($sub) => $owned($sub->selectRaw('1')->from('journal_entries as e')->where('e.source_type', $type)->where('e.status', 'posted')->whereRaw("e.source_id = {$sourceExpr}"), 'e');

        // Transactions that should be posted but have no live entry.
        // (the bank leg of a document payment is journalled by the payment's own entry: SettlementTransactions leaves those out)
        $missing = SettlementTransactions::exclude($owned(DB::table('transactions as t')->join('accounts as a', 'a.id', '=', 't.account_id'), 't'))
            ->whereNull('t.deleted_at')->whereIn('t.type', ['income', 'expense'])->where('t.amount_cents', '>', 0)
            ->where(fn ($q) => $q->whereNull('a.opening_balance_date')->orWhereColumn('t.date', '>=', 'a.opening_balance_date'))
            ->whereNotExists($liveEntry('transaction', 'CAST(t.id AS VARCHAR(64))'))
            ->pluck('t.id')->map(fn ($id) => (int) $id)->all();

        // Transactions that should not be posted (deleted, or before the opening date) but still have a live entry.
        $stale = $owned(DB::table('transactions as t')->join('accounts as a', 'a.id', '=', 't.account_id'), 't')
            ->whereIn('t.type', ['income', 'expense'])
            ->where(fn ($q) => $q->whereNotNull('t.deleted_at')->orWhere('t.amount_cents', '<=', 0)
                ->orWhere(fn ($w) => $w->whereNotNull('a.opening_balance_date')->whereColumn('t.date', '<', 'a.opening_balance_date')))
            ->whereExists($liveEntry('transaction', 'CAST(t.id AS VARCHAR(64))'))
            ->pluck('t.id')->map(fn ($id) => (int) $id)->all();

        // Transfer groups: some live counted leg without a live entry, or a live entry with no counted leg left.
        $counted = fn ($q) => $q->whereNull('t.deleted_at')->where('t.amount_cents', '>', 0)
            ->where(fn ($w) => $w->whereNull('a.opening_balance_date')->orWhereColumn('t.date', '>=', 'a.opening_balance_date'));

        $missingGroups = $counted($owned(DB::table('transactions as t')->join('accounts as a', 'a.id', '=', 't.account_id'), 't')->whereNotNull('t.transfer_group'))
            ->whereNotExists($liveEntry('transfer', 't.transfer_group'))
            ->distinct()->pluck('t.transfer_group')->all();

        $staleGroups = $owned(DB::table('journal_entries as e'), 'e')->where('e.source_type', 'transfer')->where('e.status', 'posted')
            ->whereNotExists(fn ($sub) => $counted($owned($sub->selectRaw('1')->from('transactions as t')->join('accounts as a', 'a.id', '=', 't.account_id'), 't')->whereColumn('t.transfer_group', 'e.source_id')))
            ->pluck('e.source_id')->all();

        // Opening balances: a non-zero opening with no live entry; a live entry whose account is gone or now zero.
        $openingMissing = $owned(DB::table('accounts as a'), 'a')->whereNull('a.deleted_at')->where('a.opening_balance_cents', '<>', 0)
            ->whereNotExists($liveEntry('opening_balance', 'CAST(a.id AS VARCHAR(64))'))
            ->pluck('a.id')->map(fn ($id) => (int) $id)->all();

        $openingStale = $owned(DB::table('accounts as a'), 'a')
            ->where(fn ($q) => $q->whereNotNull('a.deleted_at')->orWhere('a.opening_balance_cents', 0))
            ->whereExists($liveEntry('opening_balance', 'CAST(a.id AS VARCHAR(64))'))
            ->pluck('a.id')->map(fn ($id) => (int) $id)->all();

        return [
            'transactions' => array_values(array_unique(array_merge($missing, $stale))),
            'transfers' => array_values(array_unique(array_merge($missingGroups, $staleGroups))),
            'openings' => array_values(array_unique(array_merge($openingMissing, $openingStale))),
        ];
    }

    /**
     * Post what is pending, then re-sync every account whose cash tie-out still fails.
     *
     * @return array{posted: int, reposted: int, reversed: int, unchanged: int, deferred: list<string>}
     */
    public function run(LedgerContext $context): array
    {
        $result = ['posted' => 0, 'reposted' => 0, 'reversed' => 0, 'unchanged' => 0, 'deferred' => []];
        $tally = function (string $outcome) use (&$result) {
            if (isset($result[$outcome])) {
                $result[$outcome]++;
            }
        };

        LedgerContext::run($context, function () use ($context, $tally, &$result) {
            $pending = $this->pending($context);

            foreach ($pending['openings'] as $id) {
                $account = Account::query()->forContext($context)->withTrashed()->find($id);
                $this->attempt($result, $tally, "account #{$id}", fn () => $this->sync->syncOpening($context, $account));
            }

            foreach ($pending['transactions'] as $id) {
                $transaction = Transaction::query()->forContext($context)->withTrashed()->with('account')->find($id);
                $this->attempt($result, $tally, "transaction #{$id}", fn () => $this->sync->syncTransaction($context, $transaction));
            }

            foreach ($pending['transfers'] as $group) {
                $this->attempt($result, $tally, "transfer {$group}", fn () => $this->sync->syncTransfer($context, $group));
            }

            foreach ($this->query->cashTieOut($context) as $row) {
                if (! $row['ok']) {
                    $account = Account::query()->forContext($context)->find($row['account_id']);
                    $this->attempt($result, $tally, "account {$row['name']}", function () use ($context, $account) {
                        $this->sync->syncAccount($context, $account);

                        return 'reposted';
                    });
                }
            }
        });

        return $result;
    }

    private function attempt(array &$result, callable $tally, string $label, callable $step): void
    {
        try {
            DB::transaction(fn () => $tally($step()));
        } catch (UnpostableSource $e) {
            $result['deferred'][] = "{$label}: {$e->getMessage()}";
        }
    }
}
