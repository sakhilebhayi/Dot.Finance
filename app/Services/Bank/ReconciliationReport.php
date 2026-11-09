<?php

namespace App\Services\Bank;

use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatementLine;
use App\Models\Transaction;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The reconciliation statement an accountant signs: the bank's closing balance, the entries not yet on the statement, the
 * entries the bank has that are dated later, and the ledger balance they prove, tied to what the books (the journals) hold
 * for the account. A completed session carries the figures as they were the moment it completed (the snapshot), so the
 * report does not drift when later entries arrive; an open session shows live figures and says so.
 *
 * The proof, in integer cents:
 *
 *    statement closing balance
 *  + entries dated on or before the statement date that are not on the statement (deposits positive, payments negative)
 *  - entries the statement has that are dated after the statement date
 *  = ledger balance on the statement date          (checked against an independent sum of every counted entry)
 *    journals' balance for the account             (checked against the ledger balance, when accounting is on)
 */
class ReconciliationReport
{
    private const ITEM_LIMIT = 300;

    public function __construct(private readonly ReconciliationBalances $balances) {}

    /** @return array<string, mixed> */
    public function snapshot(BankReconciliation $session, Account $account, int $clearedCents): array
    {
        $date = $session->statement_date->toDateString();
        $baseline = $session->baseline_date?->toDateString();
        $totals = $this->balances->outstandingTotals($account, $date, $baseline);
        $items = $this->balances->outstanding($account, $date, self::ITEM_LIMIT, $baseline);
        $after = $this->balances->clearedAfter($account, $date);
        $ledger = $this->balances->ledgerBalanceAt($account, $date);
        $gl = $this->balances->glBalanceAt($account, $date);
        $proof = $session->statement_closing_cents + $totals['in_cents'] + $totals['out_cents'] - $after;

        return [
            'statement_date' => $date,
            'currency' => $session->currency,
            'statement_closing_cents' => $session->statement_closing_cents,
            'cleared_balance_cents' => $clearedCents,
            'outstanding' => ['count' => $totals['count'], 'in_cents' => $totals['in_cents'], 'out_cents' => $totals['out_cents'], 'items' => array_map(fn ($i) => ['id' => $i->id, 'date' => $i->date, 'description' => $i->description, 'signed_cents' => $i->signed], $items), 'truncated' => $totals['count'] > count($items)],
            'cleared_after_cents' => $after,
            'ledger_balance_cents' => $ledger,
            'proof_cents' => $proof,
            'proof_ties' => $proof === $ledger,
            'gl_balance_cents' => $gl,
            'gl_ties' => $gl === null ? null : $gl === $ledger,
        ];
    }

    /**
     * Everything the report page and the CSV need, for a draft (live) or a completed session (its snapshot).
     *
     * @return array<string, mixed>
     */
    public function build(BankReconciliation $session): array
    {
        $account = Account::query()->forContext($session->ledgerContext())->withTrashed()->findOrFail($session->account_id);
        $live = $session->isDraft() || ! is_array($session->snapshot);
        $snapshot = $live ? $this->snapshot($session, $account, $this->balances->clearedBalance($account, $session->baseline_date?->toDateString())) : $session->snapshot;

        $matches = DB::table('bank_matches')->where('bank_reconciliation_id', $session->id)->where('state', 'active');

        return [
            'session' => $session,
            'account' => $account,
            'live' => $live,
            'snapshot' => $snapshot,
            'difference_cents' => $live ? $session->statement_closing_cents - $snapshot['cleared_balance_cents'] : 0,
            'counts' => [
                'matches' => (clone $matches)->count(),
                'lines' => (int) DB::table('bank_statement_lines')->whereIn('bank_match_id', (clone $matches)->select('id'))->count(),
                'entries' => (int) DB::table('transactions')->whereIn('bank_match_id', (clone $matches)->select('id'))->count(),
            ],
            'adjustments' => $session->adjustments()->get(),
            'events' => $session->events()->with('actor:id,name')->get(),
            'opening_expected_cents' => $this->previousClosing($session),
        ];
    }

    /** The closing balance of the account's reconciliation before this one: what this statement's opening balance should be. */
    public function previousClosing(BankReconciliation $session): ?int
    {
        return BankReconciliation::query()->forContext($session->ledgerContext())->where('account_id', $session->account_id)->where('status', 'completed')
            ->where('statement_date', '<', $session->statement_date->toDateString())->orderByDesc('statement_date')->value('statement_closing_cents');
    }

    /**
     * @param  array<string, mixed>  $report  from build()
     * @return list<list<string|int>>
     */
    public function csvRows(array $report): array
    {
        /** @var BankReconciliation $session */
        $session = $report['session'];
        $s = $report['snapshot'];
        $cur = $session->currency;
        $fmt = fn (int $c) => Money::toDecimalString($c);

        $rows = [
            ['Bank reconciliation', $report['account']->name, $cur],
            ['Statement date', $session->statement_date->toDateString()],
            ['Status', $report['live'] ? 'Draft (live figures, not final)' : 'Completed '.$session->completed_at?->toDateTimeString()],
            [],
            ['Line', 'Amount'],
            ['Statement closing balance', $fmt($s['statement_closing_cents'])],
            ['Plus: entries not yet on the statement ('.$s['outstanding']['count'].')', $fmt($s['outstanding']['in_cents'] + $s['outstanding']['out_cents'])],
            ['Less: entries on the statement dated after the statement date', $fmt($s['cleared_after_cents'])],
            ['Ledger balance on the statement date (proved)', $fmt($s['proof_cents'])],
            ['Ledger balance on the statement date (summed independently)', $fmt($s['ledger_balance_cents'])],
            ['Proof agrees', $s['proof_ties'] ? 'yes' : 'NO'],
            ['Journals balance for the account', $s['gl_balance_cents'] === null ? 'accounting is not switched on' : $fmt($s['gl_balance_cents'])],
            ['Journals agree with the ledger', $s['gl_ties'] === null ? 'not applicable' : ($s['gl_ties'] ? 'yes' : 'NO')],
            ['Cleared balance', $fmt($s['cleared_balance_cents'])],
            ['Difference (statement closing minus cleared)', $fmt($report['live'] ? $report['difference_cents'] : 0)],
        ];

        if ($session->variance_cents !== 0) {
            $rows[] = ['Accepted variance', $fmt($session->variance_cents), $session->variance_reason];
        }

        $rows[] = [];
        $rows[] = ['Entries not yet on the statement', 'Date', 'Amount'];
        foreach ($s['outstanding']['items'] as $item) {
            $rows[] = [$item['description'], $item['date'], $fmt($item['signed_cents'])];
        }
        if ($s['outstanding']['truncated'] ?? false) {
            $rows[] = ['(list shortened; the total above covers all '.$s['outstanding']['count'].')'];
        }

        return $rows;
    }

    /**
     * Unreconciled items by age, per account: ledger entries not on a statement and statement lines with no entry.
     *
     * @return array{buckets: list<string>, accounts: list<array<string, mixed>>}
     */
    public function ageing(LedgerContext $context, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $bucketOf = function (string $date) use ($today): int {
            $days = (int) $today->diffInDays(CarbonImmutable::parse($date), false) * -1;

            return match (true) {
                $days <= 30 => 0,
                $days <= 60 => 1,
                $days <= 90 => 2,
                default => 3,
            };
        };

        $signed = Transaction::signedCentsSql('transactions');
        $entries = Transaction::query()->forContext($context)->counted()->whereNull('bank_match_id')->whereNull('reconciliation_id')->whereRaw("({$signed}) <> 0")
            ->toBase()->groupBy('transactions.account_id', 'transactions.date')
            ->selectRaw("transactions.account_id, transactions.date, COUNT(*) AS n, SUM({$signed}) AS total")->get();

        $lines = BankStatementLine::query()->forContext($context)->whereNull('bank_match_id')->whereNull('reconciliation_id')
            ->toBase()->groupBy('account_id', 'posted_on')->selectRaw('account_id, posted_on, COUNT(*) AS n, SUM(amount_cents) AS total')->get();

        $accounts = [];
        $ensure = function (int $id) use (&$accounts) {
            return $accounts[$id] ??= ['account_id' => $id, 'entries' => array_fill(0, 4, ['n' => 0, 'cents' => 0]), 'lines' => array_fill(0, 4, ['n' => 0, 'cents' => 0])];
        };

        foreach ($entries as $row) {
            $a = $ensure((int) $row->account_id);
            $b = $bucketOf(substr((string) $row->date, 0, 10));
            $a['entries'][$b] = ['n' => $a['entries'][$b]['n'] + (int) $row->n, 'cents' => $a['entries'][$b]['cents'] + (int) $row->total];
            $accounts[(int) $row->account_id] = $a;
        }
        foreach ($lines as $row) {
            $a = $ensure((int) $row->account_id);
            $b = $bucketOf(substr((string) $row->posted_on, 0, 10));
            $a['lines'][$b] = ['n' => $a['lines'][$b]['n'] + (int) $row->n, 'cents' => $a['lines'][$b]['cents'] + (int) $row->total];
            $accounts[(int) $row->account_id] = $a;
        }

        $names = Account::query()->forContext($context)->withTrashed()->whereIn('id', array_keys($accounts))->get(['id', 'name', 'currency'])->keyBy('id');
        $out = [];
        foreach ($accounts as $id => $a) {
            $account = $names->get($id);
            if ($account === null) {
                continue;
            }
            $out[] = $a + ['name' => $account->name, 'currency' => $account->currency];
        }
        usort($out, fn ($x, $y) => strcmp($x['name'], $y['name']));

        return ['buckets' => ['0-30 days', '31-60 days', '61-90 days', 'Over 90 days'], 'accounts' => $out];
    }
}
