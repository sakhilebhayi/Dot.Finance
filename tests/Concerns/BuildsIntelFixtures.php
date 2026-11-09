<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Carbon;

/**
 * A small business with real entries, for the assistant, detector and notification tests. Time is fixed at
 * 2026-11-18 so "this month" and "last month" mean the same thing on every run.
 */
trait BuildsIntelFixtures
{
    use BuildsCompanyLedgers;

    protected function fixTime(string $at = '2026-11-18 10:00:00'): void
    {
        Carbon::setTestNow($at);
    }

    /** @return array{owner: User, team: Team, context: LedgerContext, bank: Account, food: Category, fuel: Category, sales: Category} */
    protected function business(string $name = 'Acme (Pty) Ltd'): array
    {
        $owner = $this->user();
        $team = $this->company($owner, $name);

        return [
            'owner' => $owner,
            'team' => $team,
            'context' => LedgerContext::company($team),
            'bank' => $this->companyAccount($team, ['name' => 'Cheque', 'type' => 'checking', 'opening_balance_cents' => 1000000, 'opening_balance_date' => '2026-01-01']),
            'food' => $this->companyCategory($team, ['name' => 'Food', 'type' => 'expense']),
            'fuel' => $this->companyCategory($team, ['name' => 'Fuel', 'type' => 'expense']),
            'sales' => $this->companyCategory($team, ['name' => 'Sales', 'type' => 'income']),
        ];
    }

    protected function spend(Account $account, ?Category $category, int $cents, string $date, string $description = 'Spend', string $type = 'expense'): Transaction
    {
        return Transaction::factory()->create([
            'account_id' => $account->id, 'category_id' => $category?->id, 'amount_cents' => $cents, 'type' => $type,
            'date' => $date, 'description' => $description,
        ]);
    }
}
