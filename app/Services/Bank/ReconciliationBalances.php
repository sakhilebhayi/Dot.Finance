<?php

namespace App\Services\Bank;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\Accounting\GlSync;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\DB;

/**
 * The arithmetic of a reconciliation, in SQL and in integer cents, over one cash account.
 *
 *   cleared balance   the account's opening balance plus every counted row that is matched to a statement line.
 *                     A session completes when the statement's closing balance equals this exactly.
 *   ledger balance    the same account on a date: opening balance plus every counted row dated on or before it.
 *
 * "Counted" is the rule the derived balance already uses (a row dated before the account's opening date is history the
 * opening balance absorbed), so these figures can never disagree with the balance shown on the accounts page.
 */
final class ReconciliationBalances
{
    /**
     * What the books say the bank holds: the opening balance plus every counted row that is cleared, that is matched to a statement
     * line, reconciled earlier, or (on an account's first reconciliation only, when $baseline is given) dated before the baseline
     * and so taken as already agreed. A session completes when the statement's closing balance equals this exactly.
     */
    public function clearedBalance(Account $account, ?string $baseline = null): int
    {
        $signed = Transaction::signedCentsSql('transactions');

        $sum = Transaction::query()->forContext($account->ledgerContext())->counted()
            ->where('account_id', $account->id)
            ->where(function ($q) use ($baseline) {
                $q->whereNotNull('bank_match_id')->orWhereNotNull('reconciliation_id');
                if ($baseline !== null) {
                    $q->orWhere('date', '<', $baseline);
                }
            })
            ->toBase()->selectRaw("COALESCE(SUM({$signed}), 0) AS total")->value('total');

        return (int) $account->opening_balance_cents + (int) $sum;
    }

    /**
     * The books' side of the statement's opening balance: what the account held before the statement started, with rows matched
     * to this statement's own lines left out (they belong to the movement). On a later session it is simply what the last one cleared.
     */
    public function broughtForward(Account $account, ?string $baseline, ?int $previousClosing): ?int
    {
        if ($baseline === null) {
            return $previousClosing;
        }

        $signed = Transaction::signedCentsSql('transactions');

        $sum = Transaction::query()->forContext($account->ledgerContext())->counted()
            ->where('account_id', $account->id)->where('date', '<', $baseline)->whereNull('bank_match_id')->whereNull('reconciliation_id')
            ->toBase()->selectRaw("COALESCE(SUM({$signed}), 0) AS total")->value('total');

        return (int) $account->opening_balance_cents + (int) $sum;
    }

    public function ledgerBalanceAt(Account $account, string $date): int
    {
        $signed = Transaction::signedCentsSql('transactions');

        $sum = Transaction::query()->forContext($account->ledgerContext())->counted()
            ->where('account_id', $account->id)->where('date', '<=', $date)
            ->toBase()->selectRaw("COALESCE(SUM({$signed}), 0) AS total")->value('total');

        return (int) $account->opening_balance_cents + (int) $sum;
    }

    /** Signed total of the counted rows matched to statement lines but dated after $date (the bank has them, the date does not yet). */
    public function clearedAfter(Account $account, string $date): int
    {
        $signed = Transaction::signedCentsSql('transactions');

        return (int) Transaction::query()->forContext($account->ledgerContext())->counted()
            ->where('account_id', $account->id)->where(fn ($q) => $q->whereNotNull('bank_match_id')->orWhereNotNull('reconciliation_id'))->where('date', '>', $date)
            ->toBase()->selectRaw("COALESCE(SUM({$signed}), 0) AS total")->value('total');
    }

    /**
     * Rows not yet on a statement, dated on or before $date, oldest first: the outstanding deposits and payments.
     *
     * @return list<object{id: int, date: string, description: string, type: string, signed: int, amount_cents: int}>
     */
    public function outstanding(Account $account, string $date, ?int $limit = null, ?string $baseline = null): array
    {
        $signed = Transaction::signedCentsSql('transactions');

        $query = Transaction::query()->forContext($account->ledgerContext())->counted()
            ->where('account_id', $account->id)->whereNull('bank_match_id')->whereNull('reconciliation_id')->where('date', '<=', $date)
            ->when($baseline !== null, fn ($q) => $q->where('date', '>=', $baseline))
            ->whereRaw("({$signed}) <> 0")
            ->orderBy('date')->orderBy('id')
            ->toBase()->selectRaw("transactions.id, transactions.date, transactions.description, transactions.type, transactions.amount_cents, ({$signed}) AS signed");

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn ($r) => (object) ['id' => (int) $r->id, 'date' => substr((string) $r->date, 0, 10), 'description' => (string) $r->description, 'type' => (string) $r->type, 'signed' => (int) $r->signed, 'amount_cents' => (int) $r->amount_cents])->all();
    }

    /** @return array{count: int, in_cents: int, out_cents: int} totals of what outstanding() lists, without loading it */
    public function outstandingTotals(Account $account, string $date, ?string $baseline = null): array
    {
        $signed = Transaction::signedCentsSql('transactions');

        $row = Transaction::query()->forContext($account->ledgerContext())->counted()
            ->where('account_id', $account->id)->whereNull('bank_match_id')->whereNull('reconciliation_id')->where('date', '<=', $date)
            ->when($baseline !== null, fn ($q) => $q->where('date', '>=', $baseline))
            ->whereRaw("({$signed}) <> 0")
            ->toBase()
            ->selectRaw("COUNT(*) AS n, COALESCE(SUM(CASE WHEN ({$signed}) > 0 THEN ({$signed}) ELSE 0 END), 0) AS money_in, COALESCE(SUM(CASE WHEN ({$signed}) < 0 THEN ({$signed}) ELSE 0 END), 0) AS money_out")
            ->first();

        return ['count' => (int) $row->n, 'in_cents' => (int) $row->money_in, 'out_cents' => (int) $row->money_out];
    }

    /** What the journals hold for this cash account up to $date (D-115's rule), or null when accounting is off for the ledger. */
    public function glBalanceAt(Account $account, string $date): ?int
    {
        $context = $account->ledgerContext();

        if (! $this->accountingOn($context)) {
            return null;
        }

        $query = DB::table('journal_lines as l')->where('l.cash_account_id', $account->id)->where('l.entry_date', '<=', $date);
        $context->isCompany() ? $query->where('l.team_id', $context->teamId) : $query->where('l.user_id', $context->userId)->whereNull('l.team_id');

        return (int) $query->selectRaw('COALESCE(SUM(CASE WHEN l.txn_currency IS NOT NULL THEN CASE WHEN l.debit_cents > 0 THEN l.txn_amount_cents ELSE -l.txn_amount_cents END ELSE l.debit_cents - l.credit_cents END), 0) AS net')->value('net');
    }

    public function accountingOn(LedgerContext $context): bool
    {
        return app(GlSync::class)->isEnabled($context);
    }
}
