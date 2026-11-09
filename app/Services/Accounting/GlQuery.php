<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side queries over the journals. Every query is confined to one ledger by an explicit owner
 * condition (these use the query builder for speed, which bypasses the model scope, so the condition is
 * part of every query here and a test proves another ledger's rows never appear).
 */
class GlQuery
{
    /** Owner condition on a journal_lines (alias l) query. */
    public function ownedLines(LedgerContext $context, string $alias = 'l'): Builder
    {
        $query = DB::table("journal_lines as {$alias}");

        return $context->isCompany()
            ? $query->where("{$alias}.team_id", $context->teamId)
            : $query->where("{$alias}.user_id", $context->userId)->whereNull("{$alias}.team_id");
    }

    /**
     * Debits and credits per GL account over [from, to] (either bound optional).
     * With $excludeClosing the year-end closing entries (and their reversals) are left out, which is
     * what a profit-and-loss statement for a closed year needs.
     *
     * @return Collection<int, object{gl_account_id: int, debits: int, credits: int}>
     */
    public function totals(LedgerContext $context, ?string $from = null, ?string $to = null, bool $excludeClosing = false): Collection
    {
        $query = $this->ownedLines($context)
            ->groupBy('l.gl_account_id')
            ->selectRaw('l.gl_account_id, SUM(l.debit_cents) AS debits, SUM(l.credit_cents) AS credits');

        if ($from !== null) {
            $query->where('l.entry_date', '>=', $from);
        }
        if ($to !== null) {
            $query->where('l.entry_date', '<=', $to);
        }
        if ($excludeClosing) {
            $this->withoutClosing($query);
        }

        return $query->get()->map(fn ($r) => (object) ['gl_account_id' => (int) $r->gl_account_id, 'debits' => (int) $r->debits, 'credits' => (int) $r->credits])->keyBy('gl_account_id');
    }

    /** Net debit (positive) or credit (negative) of one account up to and including $to. */
    public function netThrough(LedgerContext $context, GlAccount $account, ?string $to = null, ?string $from = null): int
    {
        $query = $this->ownedLines($context)->where('l.gl_account_id', $account->id)->selectRaw('COALESCE(SUM(l.debit_cents), 0) - COALESCE(SUM(l.credit_cents), 0) AS net');

        if ($to !== null) {
            $query->where('l.entry_date', '<=', $to);
        }
        if ($from !== null) {
            $query->where('l.entry_date', '>=', $from);
        }

        return (int) $query->value('net');
    }

    /**
     * The lines of one account in date order with a running balance (debit positive), each tied to its
     * entry, so a figure can be traced to the journal and from there to the source row.
     *
     * @return array{lines: LengthAwarePaginator, opening_cents: int}
     */
    public function ledgerLines(LedgerContext $context, GlAccount $account, ?string $from, ?string $to, int $perPage = 50, int $page = 1): array
    {
        $base = $this->ownedLines($context)->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.gl_account_id', $account->id);

        if ($from !== null) {
            $base->where('l.entry_date', '>=', $from);
        }
        if ($to !== null) {
            $base->where('l.entry_date', '<=', $to);
        }

        $total = (clone $base)->count();
        $opening = $from !== null ? $this->netThrough($context, $account, date('Y-m-d', strtotime($from.' -1 day'))) : 0;

        $rows = (clone $base)
            ->orderBy('l.entry_date')->orderBy('e.entry_number')->orderBy('l.line_no')
            ->select('l.id', 'l.entry_date', 'l.debit_cents', 'l.credit_cents', 'l.description', 'l.txn_currency', 'l.txn_amount_cents', 'e.id as entry_id', 'e.entry_number', 'e.memo', 'e.source_type', 'e.source_id', 'e.status')
            ->forPage($page, $perPage)->get();

        // The running balance for page N starts from everything before the page.
        $before = $page > 1
            ? (clone $base)->orderBy('l.entry_date')->orderBy('e.entry_number')->orderBy('l.line_no')->limit(($page - 1) * $perPage)->get(['l.debit_cents', 'l.credit_cents'])->sum(fn ($r) => (int) $r->debit_cents - (int) $r->credit_cents)
            : 0;

        $running = $opening + $before;
        $rows = $rows->map(function ($r) use (&$running) {
            $running += (int) $r->debit_cents - (int) $r->credit_cents;
            $r->running_cents = $running;

            return $r;
        });

        return [
            'lines' => new LengthAwarePaginator($rows, $total, $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]),
            'opening_cents' => $opening,
        ];
    }

    /**
     * Income and expense for a period, from the journals (closing entries excluded).
     *
     * @return array{income_cents: int, expense_cents: int, net_cents: int}
     */
    public function incomeExpense(LedgerContext $context, string $from, string $to): array
    {
        $rows = $this->ownedLines($context)->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->whereIn('g.class', ['income', 'expense'])->whereBetween('l.entry_date', [$from, $to])
            ->groupBy('g.class')->selectRaw('g.class, SUM(l.debit_cents) AS debits, SUM(l.credit_cents) AS credits');
        $this->withoutClosing($rows);
        $rows = $rows->get()->keyBy('class');

        $income = (int) ($rows['income']->credits ?? 0) - (int) ($rows['income']->debits ?? 0);
        $expense = (int) ($rows['expense']->debits ?? 0) - (int) ($rows['expense']->credits ?? 0);

        return ['income_cents' => $income, 'expense_cents' => $expense, 'net_cents' => $income - $expense];
    }

    /**
     * Each cash account's balance as the cash ledger derives it against what the journals say. A foreign
     * currency account is compared in its own currency (the original amount carried on the lines).
     *
     * @return list<array{account_id: int, name: string, currency: string, cash_cents: int, gl_cents: int, difference_cents: int, ok: bool}>
     */
    public function cashTieOut(LedgerContext $context): array
    {
        $gl = $this->ownedLines($context)->whereNotNull('l.cash_account_id')->groupBy('l.cash_account_id')
            ->selectRaw('l.cash_account_id, SUM(CASE WHEN l.txn_currency IS NOT NULL THEN CASE WHEN l.debit_cents > 0 THEN l.txn_amount_cents ELSE -l.txn_amount_cents END ELSE l.debit_cents - l.credit_cents END) AS net')
            ->pluck('net', 'cash_account_id');

        return Account::query()->forContext($context)->withBalance()->orderBy('name')->get()->map(function (Account $a) use ($gl) {
            $glCents = (int) ($gl[$a->id] ?? 0);
            $cash = $a->balanceCents();

            return [
                'account_id' => $a->id, 'name' => $a->name, 'currency' => $a->currency,
                'cash_cents' => $cash, 'gl_cents' => $glCents, 'difference_cents' => $cash - $glCents, 'ok' => $cash === $glCents,
            ];
        })->all();
    }

    /**
     * Where a journal entry came from, for drill-down: a label and (when there is somewhere to go) a route name and parameter.
     *
     * @return array{label: string, route: ?string, parameter: mixed}
     */
    public function source(JournalEntry $entry): array
    {
        return match ($entry->source_type) {
            'transaction' => ['label' => 'Transaction #'.$entry->source_id, 'route' => 'transactions.edit', 'parameter' => $entry->source_id],
            'transfer' => ['label' => 'Transfer', 'route' => 'transactions.index', 'parameter' => null],
            'opening_balance' => ['label' => 'Opening balance of account #'.$entry->source_id, 'route' => 'accounts.edit', 'parameter' => $entry->source_id],
            // R44 SALES: documents and money received, so "show the entries" can go back to what made them
            'invoice', 'credit_note' => ['label' => ($entry->source_type === 'credit_note' ? 'Credit note ' : 'Invoice ').'#'.$entry->source_id, 'route' => 'sales.documents.show', 'parameter' => $entry->source_id],
            // R-44 acceptance (D-1602): a supplier bill or claim, a supplier payment and a payroll summary lead back to their own page too.
            'bill' => str_starts_with((string) $entry->source_id, 'claim-')
                ? ['label' => 'Expense claim #'.substr((string) $entry->source_id, 6), 'route' => 'purchases.claims.show', 'parameter' => substr((string) $entry->source_id, 6)]
                : ['label' => 'Supplier bill #'.$entry->source_id, 'route' => 'purchases.bills.show', 'parameter' => $entry->source_id],
            'payroll' => ['label' => 'Payroll summary #'.$entry->source_id, 'route' => 'tax.payroll.show', 'parameter' => $entry->source_id],
            'payment' => ctype_digit((string) $entry->source_id)
                ? $this->paymentSource((int) $entry->source_id)
                : ['label' => 'Exchange difference', 'route' => null, 'parameter' => null],
            'reversal' => ['label' => 'Reversal of entry #'.$entry->source_id, 'route' => 'accounting.journals.show', 'parameter' => $entry->source_id],
            'manual' => ['label' => 'Manual journal', 'route' => null, 'parameter' => null],
            'period_close' => ['label' => 'Year-end close', 'route' => 'accounting.periods.index', 'parameter' => null],
            default => ['label' => ucfirst(str_replace('_', ' ', $entry->source_type)).' '.$entry->source_id, 'route' => null, 'parameter' => null],
        };
    }

    /** @var array<int, array{label: string, route: string, parameter: int}> */
    private array $paymentSources = [];

    /**
     * Money in is a receipt (Sales); money out is a supplier payment (Purchases) unless it is a refund Sales gave back to a customer.
     *
     * @return array{label: string, route: string, parameter: int}
     */
    private function paymentSource(int $id): array
    {
        return $this->paymentSources[$id] ??= (function () use ($id) {
            $payment = DB::table('payments')->where('id', $id)->first(['direction']);
            $refund = $payment !== null && $payment->direction === 'out' && DB::table('sales_refunds')->where('payment_id', $id)->exists();
            $sales = $payment === null || $payment->direction === 'in' || $refund;

            return ['label' => ($sales ? ($refund ? 'Refund #' : 'Payment received #') : 'Supplier payment #').$id, 'route' => $sales ? 'sales.payments.show' : 'purchases.payments.show', 'parameter' => $id];
        })();
    }

    /** Exclude year-end closing entries and the reversals of them. */
    private function withoutClosing(Builder $query): void
    {
        $query->whereNotExists(function ($sub) {
            $sub->selectRaw('1')->from('journal_entries as ce')
                ->whereColumn('ce.id', 'l.journal_entry_id')
                ->where(function ($q) {
                    $q->where('ce.source_type', 'period_close')
                        ->orWhereIn('ce.reverses_entry_id', fn ($s) => $s->select('pc.id')->from('journal_entries as pc')->where('pc.source_type', 'period_close'));
                });
        });
    }
}
