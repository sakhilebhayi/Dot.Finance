<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-currency position of one ledger (a person's or a company's). Nothing here ever adds two
 * currencies together: every figure is keyed by ISO currency code.
 */
class LedgerSummary
{
    /**
     * Derived balance, this month's income and expense, and net, per currency.
     * Transfers are excluded from income and expense: they move money between
     * the ledger's own accounts and are neither.
     *
     * @return array<string, array{currency: string, accounts: int, balance_cents: int, income_cents: int, expense_cents: int, net_cents: int}>
     */
    public function position(LedgerContext $context, ?Carbon $month = null): array
    {
        $month ??= now();

        $accounts = Account::query()->forContext($context)->withBalance()->get();

        $position = [];
        foreach ($accounts as $account) {
            $position[$account->currency] ??= $this->blank($account->currency);
            $position[$account->currency]['accounts']++;
            $position[$account->currency]['balance_cents'] += $account->balanceCents();
        }

        $rows = Transaction::query()->forContext($context)
            ->whereIn('type', ['income', 'expense'])
            ->whereDate('date', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->selectRaw('currency, type, SUM(amount_cents) AS total')
            ->groupBy('currency', 'type')
            ->get();

        foreach ($rows as $row) {
            $currency = $row->currency ?? 'ZAR';
            $position[$currency] ??= $this->blank($currency);
            $position[$currency][$row->type === 'income' ? 'income_cents' : 'expense_cents'] += (int) $row->total;
        }

        foreach ($position as &$entry) {
            $entry['net_cents'] = $entry['income_cents'] - $entry['expense_cents'];
        }
        unset($entry);

        ksort($position);

        return $position;
    }

    /** @return Collection<int, Account> accounts with their derived balance loaded */
    public function accounts(LedgerContext $context): Collection
    {
        return Account::query()->forContext($context)->withBalance()->orderBy('name')->get();
    }

    private function blank(string $currency): array
    {
        return ['currency' => $currency, 'accounts' => 0, 'balance_cents' => 0, 'income_cents' => 0, 'expense_cents' => 0, 'net_cents' => 0];
    }
}
