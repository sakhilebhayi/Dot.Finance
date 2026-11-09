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
            $rows = Transaction::query()->forContext($context)->where('account_id', $account->id)->get();

            $recomputed = $account->opening_balance_cents;
            $counted = 0;
            foreach ($rows as $row) {
                if ($account->opening_balance_date !== null && $row->date->lt($account->opening_balance_date)) {
                    continue;
                }
                $recomputed += $row->signedCents();
                $counted++;
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

            foreach ($rows as $row) {
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

    /** @return list<array{severity: string, code: string, message: string}> */
    private function transferFindings(LedgerContext $context): array
    {
        $query = Transaction::query()->forContext($context)->whereNotNull('transfer_group');

        $findings = [];
        foreach ($query->get()->groupBy('transfer_group') as $group => $legs) {
            $out = $legs->where('transfer_direction', 'out');
            $in = $legs->where('transfer_direction', 'in');

            if ($legs->count() === 1 && Transaction::onlyTrashed()->forContext($context)->where('transfer_group', $group)->exists()) {
                // The other leg was deleted along with its account. That money
                // already moved, so this is history, not damage.
                $findings[] = $this->finding('info', 'transfer_peer_deleted', sprintf(
                    'Transfer %s: the other leg was deleted with its account; this leg still counts.', $group
                ));

                continue;
            }

            if ($legs->count() !== 2 || $out->count() !== 1 || $in->count() !== 1) {
                $findings[] = $this->finding('error', 'orphan_transfer_leg', sprintf(
                    'Transfer %s has %d leg(s); a transfer must have exactly one out and one in.', $group, $legs->count()
                ));

                continue;
            }

            $outLeg = $out->first();
            $inLeg = $in->first();

            if ($outLeg->currency === $inLeg->currency && $outLeg->amount_cents !== $inLeg->amount_cents) {
                $findings[] = $this->finding('error', 'transfer_amount_mismatch', sprintf(
                    'Transfer %s moves %d out but %d in within one currency.', $group, $outLeg->amount_cents, $inLeg->amount_cents
                ));
            }

            if ($outLeg->account_id === $inLeg->account_id) {
                $findings[] = $this->finding('error', 'transfer_same_account', sprintf('Transfer %s starts and ends in the same account.', $group));
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
