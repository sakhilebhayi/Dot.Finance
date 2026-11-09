<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Accounting\LedgerSetting;
use App\Models\Transaction;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\GlSweeper;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Re-derives every account balance a second, independent way and checks the
 * ledger's structural invariants, so a corrupt row shows up as a named finding
 * instead of as a quietly wrong total.
 *
 * Balances are derived live in SQL (Account::withBalance), so they cannot
 * "drift" from a stored number the way the old typed balance did. What can go
 * wrong is the data underneath: a transfer with one leg, a transaction whose
 * currency disagrees with its account, an amount whose cents and legacy decimal
 * mirror disagree, a legacy row nobody backfilled, a row tied to a record in another
 * ledger (a company transaction on a personal account). This checks all of those, and
 * recomputes each balance in PHP from the raw rows to confirm the SQL expression
 * itself.
 */
class LedgerReconciler
{
    /**
     * Check every ledger there is, one at a time (the console check). Each ledger's accounts
     * and findings are reported on their own; nothing is merged across ledgers.
     *
     * @return array{accounts: list<array<string, mixed>>, findings: list<array{severity: string, code: string, message: string}>}
     */
    public function runAll(): array
    {
        $contexts = LedgerContext::owningRowsIn('accounts')
            ->merge(LedgerContext::owningRowsIn('transactions'))
            ->unique(fn (LedgerContext $c) => $c->key());

        $report = ['accounts' => [], 'findings' => []];

        foreach ($contexts as $context) {
            $one = $this->run($context);
            $report['accounts'] = array_merge($report['accounts'], $one['accounts']);
            $report['findings'] = array_merge($report['findings'], $one['findings']);
        }

        return $report;
    }

    /**
     * @return array{accounts: list<array<string, mixed>>, findings: list<array{severity: string, code: string, message: string}>}
     */
    public function run(LedgerContext $context): array
    {
        $accounts = Account::query()->forContext($context)->withBalance()->orderBy('name')->get();

        $findings = [];
        $summary = [];

        foreach ($accounts as $account) {
            // One pass in id order, 2,000 rows at a time: an account can hold hundreds of thousands of rows and the check runs
            // under the host's memory limit, so the rows are never all in memory (nor loaded twice).
            $recomputed = $account->opening_balance_cents;
            $counted = 0;
            $rows = Transaction::query()->forContext($context)->where('account_id', $account->id)->lazyById(2000);
            foreach ($rows as $row) {
                if ($account->opening_balance_date === null || ! $row->date->lt($account->opening_balance_date)) {
                    $recomputed += $row->signedCents();
                    $counted++;
                }
                foreach ($this->rowFindings($row, $account) as $finding) {
                    $findings[] = $finding;
                }
            }
            $derived = $account->balanceCents();
            $summary[] = [
                'account_id' => $account->id,
                'ledger' => $context->key(),
                'ledger_label' => $context->label(),
                'name' => $account->name,
                'currency' => $account->currency,
                'opening_cents' => $account->opening_balance_cents,
                'opening_date' => $account->opening_balance_date?->toDateString(),
                'transactions_counted' => $counted,
                'derived_cents' => $derived,
                'recomputed_cents' => $recomputed,
                'drift_cents' => $derived - $recomputed,
            ];

            if ($derived !== $recomputed) {
                $findings[] = $this->finding('error', 'balance_drift', sprintf(
                    'Account "%s": SQL balance %s does not match independent recompute %s.',
                    $account->name, Money::format($derived, $account->currency), Money::format($recomputed, $account->currency)
                ));
            }

        }

        return ['accounts' => $summary, 'findings' => array_merge(
            $findings,
            $this->transferFindings($context),
            $this->crossContextFindings($context),
            $this->preOpeningFindings($context),
            $this->recurringDuplicateFindings($context),
            $this->journalFindings($context),
        )];
    }

    /** The per-row checks: currency, the legacy decimal mirror, an orphan legacy transfer. @return list<array{severity: string, code: string, message: string}> */
    private function rowFindings(Transaction $row, Account $account): array
    {
        $findings = [];
        if ($row->currency !== $account->currency) {
            $findings[] = $this->finding('error', 'currency_mismatch', sprintf(
                'Transaction #%d is %s but its account "%s" is %s.', $row->id, $row->currency ?? 'unset', $account->name, $account->currency
            ));
        }
        if (Money::fromDecimal($row->getRawOriginal('amount')) !== $row->amount_cents) {
            $findings[] = $this->finding(
                'error',
                $row->amount_cents === 0 ? 'unmigrated_legacy_row' : 'amount_mirror_mismatch',
                $row->amount_cents === 0
                    ? sprintf('Transaction #%d still has no cents value (run finance:backfill-money).', $row->id)
                    : sprintf('Transaction #%d: amount_cents %d disagrees with the legacy decimal %s.', $row->id, $row->amount_cents, $row->getRawOriginal('amount'))
            );
        }
        if ($row->type === 'transfer' && $row->transfer_group === null) {
            $findings[] = $this->finding('info', 'legacy_transfer_without_destination', sprintf(
                'Transaction #%d is a legacy transfer with no destination account; it has never moved a balance and is not counted.', $row->id
            ));
        }

        return $findings;
    }

    /**
     * Rows dated before their account's opening date. They are kept, listed on the transactions page, and
     * excluded from the balance AND from every report (decision D-100), so nothing disagrees; the finding
     * tells the owner they exist so a statement imported from before the opening date is not a surprise.
     *
     * @return list<array{severity: string, code: string, message: string}>
     */
    private function preOpeningFindings(LedgerContext $context): array
    {
        $rows = DB::table('transactions as t')->join('accounts as a', 'a.id', '=', 't.account_id')
            ->where(fn ($q) => $context->isCompany() ? $q->where('t.team_id', $context->teamId) : $q->where('t.user_id', $context->userId)->whereNull('t.team_id'))
            ->whereNull('t.deleted_at')->whereNull('a.deleted_at')
            ->whereNotNull('a.opening_balance_date')->whereColumn('t.date', '<', 'a.opening_balance_date')
            ->groupBy('a.id', 'a.name')->selectRaw('a.name, COUNT(*) AS n')->get();

        return $rows->map(fn ($r) => $this->finding('warning', 'pre_opening_transactions', sprintf(
            'Account "%s" has %d transaction(s) dated before its opening date. They are kept as history but excluded from its balance and from every report.', $r->name, $r->n
        )))->all();
    }

    /** @return list<array{severity: string, code: string, message: string}> */
    private function recurringDuplicateFindings(LedgerContext $context): array
    {
        $rows = DB::table('transactions as t')
            ->where(fn ($q) => $context->isCompany() ? $q->where('t.team_id', $context->teamId) : $q->where('t.user_id', $context->userId)->whereNull('t.team_id'))
            ->whereNotNull('t.recurring_rule_id')->whereNull('t.deleted_at')
            ->groupBy('t.recurring_rule_id', 't.date')->havingRaw('COUNT(*) > 1')
            ->selectRaw('t.recurring_rule_id, t.date, COUNT(*) AS n')->limit(50)->get();

        return $rows->map(fn ($r) => $this->finding('error', 'recurring_duplicate', sprintf(
            'Recurring rule #%d produced %d entries for %s; delete the extra copies.', $r->recurring_rule_id, $r->n, substr((string) $r->date, 0, 10)
        )))->all();
    }

    /**
     * With accounting on: every cash account must tie out to its journal postings, and every cash-ledger row
     * must be reflected in the journals. Both are findings the daily verify also raises.
     *
     * @return list<array{severity: string, code: string, message: string}>
     */
    private function journalFindings(LedgerContext $context): array
    {
        $settings = LedgerSetting::query()->forContext($context)->first();

        if ($settings === null || ! $settings->isEnabled()) {
            return [];
        }

        $findings = [];

        foreach (app(GlQuery::class)->cashTieOut($context) as $row) {
            if (! $row['ok']) {
                $findings[] = $this->finding('error', 'gl_cash_tieout', sprintf(
                    'Account "%s": the cash ledger says %s but the journals say %s (difference %s).',
                    $row['name'], Money::format($row['cash_cents'], $row['currency']), Money::format($row['gl_cents'], $row['currency']), Money::format($row['difference_cents'], $row['currency'])
                ));
            }
        }

        $pending = app(GlSweeper::class)->pending($context);
        $count = count($pending['transactions']) + count($pending['transfers']) + count($pending['openings']);
        if ($count > 0) {
            $findings[] = $this->finding('warning', 'gl_pending', "{$count} cash-ledger row(s) are not reflected in the journals yet (finance:gl:post-pending posts them).");
        }

        return $findings;
    }

    /**
     * A row that points at a record in another ledger. The models refuse to write one, so
     * finding one means something wrote around them (raw SQL, an old import, a bad restore).
     *
     * @return list<array{severity: string, code: string, message: string}>
     */
    private function crossContextFindings(LedgerContext $context): array
    {
        $mine = fn ($q, string $alias) => $context->isCompany()
            ? $q->where("{$alias}.team_id", $context->teamId)
            : $q->where("{$alias}.user_id", $context->userId)->whereNull("{$alias}.team_id");
        $differs = fn (string $child, string $parent) => "(COALESCE({$child}.user_id, 0) <> COALESCE({$parent}.user_id, 0) OR COALESCE({$child}.team_id, 0) <> COALESCE({$parent}.team_id, 0))";

        $checks = [
            ['transactions', 'account_id', 'accounts', 'Transaction'],
            ['transactions', 'category_id', 'categories', 'Transaction'],
            ['budgets', 'category_id', 'categories', 'Budget'],
            ['recurring_rules', 'account_id', 'accounts', 'Recurring rule'],
            ['recurring_rules', 'category_id', 'categories', 'Recurring rule'],
        ];

        $findings = [];
        foreach ($checks as [$child, $column, $parent, $label]) {
            $query = DB::table("{$child} as c")->join("{$parent} as p", 'p.id', '=', "c.{$column}")->whereRaw($differs('c', 'p'));
            $mine($query, 'c');

            foreach ($query->pluck('c.id') as $id) {
                $findings[] = $this->finding('error', 'context_mismatch', sprintf(
                    '%s #%d points (%s) at a %s in a different ledger.', $label, $id, $column, $parent === 'accounts' ? 'account' : 'category'
                ));
            }
        }

        return $findings;
    }

    /** Transfers pair an "out" with an "in"; this finds the pairs that are not (a few at most), and the pairs that disagree, in the database. */
    private function transferFindings(LedgerContext $context): array
    {
        $owned = fn ($q, string $alias) => $context->isCompany()
            ? $q->where("{$alias}.team_id", $context->teamId)
            : $q->where("{$alias}.user_id", $context->userId)->whereNull("{$alias}.team_id");
        $findings = [];

        $misshapen = $owned(DB::table('transactions as t'), 't')->whereNull('t.deleted_at')->whereNotNull('t.transfer_group')->groupBy('t.transfer_group')
            ->havingRaw("COUNT(*) <> 2 OR SUM(CASE WHEN t.transfer_direction = 'out' THEN 1 ELSE 0 END) <> 1 OR SUM(CASE WHEN t.transfer_direction = 'in' THEN 1 ELSE 0 END) <> 1")
            ->selectRaw('t.transfer_group, COUNT(*) AS legs')->get();

        foreach ($misshapen as $g) {
            if ((int) $g->legs === 1 && Transaction::onlyTrashed()->forContext($context)->where('transfer_group', $g->transfer_group)->exists()) {
                // The other leg was deleted along with its account. That money already moved, so this is history, not damage.
                $findings[] = $this->finding('info', 'transfer_peer_deleted', sprintf('Transfer %s: the other leg was deleted with its account; this leg still counts.', $g->transfer_group));

                continue;
            }
            $findings[] = $this->finding('error', 'orphan_transfer_leg', sprintf('Transfer %s has %d leg(s); a transfer must have exactly one out and one in.', $g->transfer_group, $g->legs));
        }

        $skip = $misshapen->pluck('transfer_group')->all();
        $pairs = $owned(DB::table('transactions as o')->join('transactions as i', function ($j) {
            $j->on('i.transfer_group', '=', 'o.transfer_group')->where('i.transfer_direction', 'in')->whereNull('i.deleted_at');
        }), 'o')->where('o.transfer_direction', 'out')->whereNull('o.deleted_at')->whereNotNull('o.transfer_group')
            ->when($skip !== [], fn ($q) => $q->whereNotIn('o.transfer_group', $skip))
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereColumn('o.currency', 'i.currency')->whereColumn('o.amount_cents', '<>', 'i.amount_cents'))->orWhereColumn('o.account_id', 'i.account_id'))
            ->get(['o.transfer_group', 'o.currency as out_currency', 'i.currency as in_currency', 'o.amount_cents as out_cents', 'i.amount_cents as in_cents', 'o.account_id as out_account', 'i.account_id as in_account']);

        foreach ($pairs as $p) {
            if ($p->out_currency === $p->in_currency && (int) $p->out_cents !== (int) $p->in_cents) {
                $findings[] = $this->finding('error', 'transfer_amount_mismatch', sprintf('Transfer %s moves %d out but %d in within one currency.', $p->transfer_group, $p->out_cents, $p->in_cents));
            }
            if ((int) $p->out_account === (int) $p->in_account) {
                $findings[] = $this->finding('error', 'transfer_same_account', sprintf('Transfer %s starts and ends in the same account.', $p->transfer_group));
            }
        }

        return $findings;
    }

    private function finding(string $severity, string $code, string $message): array
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message];
    }

    /** Findings that make the check fail (info findings are reported but pass). */
    public static function problems(array $report): array
    {
        return array_values(array_filter($report['findings'], fn (array $f) => $f['severity'] === 'error'));
    }
}
