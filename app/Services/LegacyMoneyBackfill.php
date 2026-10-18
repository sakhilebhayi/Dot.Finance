<?php

namespace App\Services;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * One-off, idempotent conversion of the pre-ledger money columns.
 *
 * Run by the migration, and runnable again by hand (`finance:backfill-money`)
 * for rows written by old code in the window between the migration and the
 * new code going live. It only ever fills rows that are still unmigrated, so
 * running it twice, or after real use, never changes an adjusted balance.
 */
class LegacyMoneyBackfill
{
    /**
     * @return array{accounts: int, transactions: int, budgets: int}
     */
    public function run(): array
    {
        return [
            'accounts' => $this->accounts(),
            'transactions' => $this->transactions(),
            'budgets' => $this->budgets(),
        ];
    }

    /**
     * Unmigrated = no opening date yet. The typed balance becomes the opening
     * balance, effective today; transactions already in the ledger before today
     * are history the typed number already reflects (or didn't).
     */
    private function accounts(): int
    {
        $today = now()->toDateString();
        $count = 0;

        DB::table('accounts')->whereNull('opening_balance_date')->orderBy('id')->chunkById(500, function ($rows) use ($today, &$count) {
            foreach ($rows as $row) {
                DB::table('accounts')->where('id', $row->id)->update([
                    'opening_balance_cents' => Money::fromDecimal($row->balance),
                    'opening_balance_date' => $today,
                ]);
                $count++;
            }
        });

        return $count;
    }

    private function transactions(): int
    {
        $count = 0;

        DB::table('transactions')->where('amount_cents', 0)->where('amount', '<>', 0)->orderBy('id')->chunkById(1000, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                DB::table('transactions')->where('id', $row->id)->update(['amount_cents' => Money::fromDecimal($row->amount)]);
                $count++;
            }
        });

        // Currency is inherited from the account. Portable correlated subquery.
        DB::statement('UPDATE transactions SET currency = (SELECT accounts.currency FROM accounts WHERE accounts.id = transactions.account_id) WHERE currency IS NULL');

        return $count;
    }

    /**
     * A budget never had a currency. It gets the currency of its ledger's most-used
     * account (the evident home currency), falling back to ZAR, the app default.
     */
    private function budgets(): int
    {
        $count = 0;

        DB::table('budgets')->where('amount_cents', 0)->where('amount', '<>', 0)->orderBy('id')->chunkById(500, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                // A company budget (team_id set) reads its own company's accounts; before the
                // company-ledger migration the column does not exist and every budget is personal.
                $companyId = property_exists($row, 'team_id') ? $row->team_id : null;
                $currency = DB::table('accounts')
                    ->when($companyId !== null, fn ($q) => $q->where('team_id', $companyId), fn ($q) => $q->where('user_id', $row->user_id))
                    ->select('currency', DB::raw('COUNT(*) as uses'))->groupBy('currency')->orderByDesc('uses')->orderBy('currency')
                    ->value('currency') ?? 'ZAR';

                DB::table('budgets')->where('id', $row->id)->update([
                    'amount_cents' => Money::fromDecimal($row->amount),
                    'currency' => $currency,
                ]);
                $count++;
            }
        });

        return $count;
    }
}
