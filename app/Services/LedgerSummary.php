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
     * One hero figure per currency (D-1700): `cash_cents` is the money actually in the checking, savings and cash accounts of
     * that currency. A card or loan (`credit` accounts) is what the ledger owes, so it is reported beside the cash in
     * `owed_cents` (a positive amount owed) and never netted into the headline; investments are not cash and sit in
     * `invested_cents`. `balance_cents` is still the sum of every account of the currency, kept for callers that
     * want the old total, and `bridge` lists the accounts that are not in the cash figure so a page can say why.
     *
     * @return array<string, array{currency: string, accounts: int, cash_accounts: int, balance_cents: int, cash_cents: int, owed_cents: int, invested_cents: int, bridge: list<array{name: string, type: string, balance_cents: int}>, income_cents: int, expense_cents: int, net_cents: int}>
     */
    public function position(LedgerContext $context, ?Carbon $month = null): array
    {
        $month ??= now();

        $accounts = app(AccountBalances::class)->forContext($context);

        $position = [];
        foreach ($accounts as $account) {
            $position[$account->currency] ??= $this->blank($account->currency);
            $position[$account->currency]['accounts']++;
            $position[$account->currency]['balance_cents'] += $account->balanceCents();

            $kind = self::kindOf((string) $account->type);
            if ($kind === 'cash') {
                $position[$account->currency]['cash_accounts']++;
                $position[$account->currency]['cash_cents'] += $account->balanceCents();
            } else {
                $kind === 'owed'
                    ? $position[$account->currency]['owed_cents'] += -$account->balanceCents()
                    : $position[$account->currency]['invested_cents'] += $account->balanceCents();
                $position[$account->currency]['bridge'][] = ['name' => (string) $account->name, 'type' => (string) $account->type, 'balance_cents' => $account->balanceCents()];
            }
        }

        $rows = Transaction::query()->forContext($context)->counted()
            ->whereIn('type', ['income', 'expense'])
            ->where('date', '>=', $month->copy()->startOfMonth()->toDateString())
            ->where('date', '<', $month->copy()->startOfMonth()->addMonth()->toDateString())
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
        return app(AccountBalances::class)->forContext($context);
    }

    /** Whether an account type holds cash (checking, savings, cash), is owed to someone else (credit) or is an investment. */
    public static function kindOf(string $type): string
    {
        return match ($type) {
            'checking', 'savings', 'cash' => 'cash',
            'credit' => 'owed',
            default => 'invested',
        };
    }

    /**
     * The currency to lead with: the ledger's base currency when the books are on, otherwise the currency that has the most
     * cash accounts. The rest follow alphabetically, so a small foreign-currency account is never the headline.
     *
     * @param  array<string, array<string, mixed>>  $position
     * @return list<string>
     */
    public static function currencyOrder(array $position, ?string $base = null): array
    {
        $codes = array_keys($position);
        $lead = ($base !== null && isset($position[$base])) ? $base : (collect($position)->sortByDesc(fn ($r) => $r['cash_accounts'] * 1000 + $r['accounts'])->keys()->first());
        usort($codes, fn ($a, $b) => [$a === $lead ? 0 : 1, $a] <=> [$b === $lead ? 0 : 1, $b]);

        return $codes;
    }

    private function blank(string $currency): array
    {
        return ['currency' => $currency, 'accounts' => 0, 'cash_accounts' => 0, 'balance_cents' => 0, 'cash_cents' => 0, 'owed_cents' => 0, 'invested_cents' => 0, 'bridge' => [], 'income_cents' => 0, 'expense_cents' => 0, 'net_cents' => 0];
    }
}
