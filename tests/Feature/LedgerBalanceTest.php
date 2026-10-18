<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_is_opening_plus_signed_transactions_on_or_after_the_opening_date(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->opening(100_000, '2026-01-01')->create();

        Transaction::factory()->for($account)->income()->cents(50_000)->on('2026-01-01')->create();   // counts: on the opening date
        Transaction::factory()->for($account)->expense()->cents(12_345)->on('2026-02-10')->create();  // counts
        Transaction::factory()->for($account)->expense()->cents(999_999)->on('2025-12-31')->create(); // before opening: history, not reconstructed

        $balance = Account::withoutGlobalScopes()->withBalance()->findOrFail($account->id)->balanceCents();

        $this->assertSame(100_000 + 50_000 - 12_345, $balance);
    }

    public function test_an_account_with_no_opening_date_counts_every_transaction(): void
    {
        $account = Account::factory()->opening(0, null)->create();
        Transaction::factory()->for($account)->income()->cents(2_500)->on('1999-01-01')->create();

        $this->assertSame(2_500, Account::withoutGlobalScopes()->withBalance()->findOrFail($account->id)->balanceCents());
    }

    public function test_balance_is_derived_for_many_accounts_in_a_single_query(): void
    {
        $user = User::factory()->create();
        Account::factory()->count(5)->for($user)->create()->each(
            fn ($a) => Transaction::factory()->for($a)->income()->cents(100)->create()
        );

        DB::enableQueryLog();
        $accounts = Account::withoutGlobalScopes()->withBalance()->where('user_id', $user->id)->get();
        $accounts->each->balanceCents();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertCount(5, $accounts);
    }

    public function test_the_typed_balance_column_no_longer_influences_anything(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(10_000, null)->create();
        DB::table('accounts')->where('id', $account->id)->update(['balance' => 999_999]);

        $this->assertSame(10_000, Account::withoutGlobalScopes()->withBalance()->findOrFail($account->id)->balanceCents());
    }

    public function test_adding_a_transaction_moves_the_dashboard_balance_without_touching_the_account(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(100_000_00, null)->create(['name' => 'Ledger-moves account']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('ZAR 100,000.00');

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id,
            'description' => 'Laptop purchase',
            'amount' => '4321.09',
            'type' => 'expense',
            'date' => now()->toDateString(),
        ])->assertRedirect();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('ZAR 95,678.91');
    }

    public function test_a_new_account_is_created_with_an_opening_balance_and_date_not_a_typed_balance(): void
    {
        Carbon::setTestNow('2026-10-18');
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->post(route('accounts.store'), [
            'name' => 'Fresh account',
            'type' => 'savings',
            'currency' => 'ZAR',
            'opening_balance' => '2500.50',
            'institution' => 'Test Bank',
        ])->assertRedirect(route('accounts.index'));

        $account = Account::where('name', 'Fresh account')->firstOrFail();
        $this->assertSame(250_050, $account->opening_balance_cents);
        $this->assertSame('2026-10-18', $account->opening_balance_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_csv_import_moves_the_derived_balance(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(100_000, null)->create();

        $this->actingAs($user)->post(route('transactions.import.store'), [
            'account_id' => $account->id,
            'rows' => [
                ['include' => '1', 'description' => 'Imported salary', 'amount' => '500.00', 'type' => 'income', 'date' => '2026-08-01'],
                ['include' => '1', 'description' => 'Imported rent', 'amount' => '120.25', 'type' => 'expense', 'date' => '2026-08-02'],
            ],
        ])->assertRedirect();

        $this->assertSame(100_000 + 50_000 - 12_025, Account::withBalance()->findOrFail($account->id)->balanceCents());
    }

    public function test_a_recurring_run_moves_the_derived_balance(): void
    {
        Carbon::setTestNow('2026-09-01');
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->opening(100_000, null)->create();
        Transaction::factory()->for($account)->expense()->cents(20_000)->on('2026-08-01')->create([
            'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'next_occurrence_date' => '2026-09-01',
        ]);

        $before = Account::withoutGlobalScopes()->withBalance()->findOrFail($account->id)->balanceCents();
        $this->artisan('finance:generate-recurring-transactions')->assertSuccessful();
        $after = Account::withoutGlobalScopes()->withBalance()->findOrFail($account->id)->balanceCents();

        $this->assertSame(80_000, $before);
        $this->assertSame(60_000, $after);

        Carbon::setTestNow();
    }

    public function test_transfer_legs_move_both_balances_and_legacy_transfers_move_nothing(): void
    {
        $user = User::factory()->create();
        $from = Account::factory()->for($user)->opening(100_000, null)->create();
        $to = Account::factory()->for($user)->opening(0, null)->create();

        app(TransferService::class)->create($user, $from, $to, 30_000, null, Carbon::today(), 'Move to savings', null);

        // A pre-existing "transfer" row: no direction, no destination. It never had a balance effect.
        Transaction::factory()->for($from)->cents(77_777)->create(['type' => 'transfer', 'transfer_direction' => null]);

        $this->assertSame(70_000, Account::withoutGlobalScopes()->withBalance()->findOrFail($from->id)->balanceCents());
        $this->assertSame(30_000, Account::withoutGlobalScopes()->withBalance()->findOrFail($to->id)->balanceCents());
    }

    public function test_dashboard_never_adds_different_currencies_together(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Account::factory()->for($user)->currency('ZAR')->opening(123_456_78, null)->create(['name' => 'Rand account for dashboard']);
        Account::factory()->for($user)->currency('USD')->opening(654_32, null)->create(['name' => 'Dollar account for dashboard']);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertSee('ZAR 123,456.78')
            ->assertSee('USD 654.32')
            ->assertDontSee('124,111.10')
            ->assertDontSee('$123,456.78')
            ->assertDontSee('$654.32');
    }

    public function test_dashboard_monthly_figures_are_per_currency_and_exclude_transfers(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $zar = Account::factory()->for($user)->currency('ZAR')->create();
        $zar2 = Account::factory()->for($user)->currency('ZAR')->create();
        $usd = Account::factory()->for($user)->currency('USD')->create();

        Transaction::factory()->for($zar)->income()->cents(777_001_23)->create();
        Transaction::factory()->for($usd)->income()->cents(888_002_34)->create();
        Transaction::factory()->for($zar)->expense()->cents(111_003_45)->create();
        app(TransferService::class)->create($user, $zar, $zar2, 999_004_56, null, Carbon::today(), 'Shuffle', null);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('ZAR 777,001.23')
            ->assertSee('USD 888,002.34')
            ->assertSee('ZAR 111,003.45');

        $response->assertViewHas('position', function (array $position) {
            return $position['ZAR']['income_cents'] === 777_001_23
                && $position['ZAR']['expense_cents'] === 111_003_45
                && $position['USD']['income_cents'] === 888_002_34
                && $position['USD']['expense_cents'] === 0;
        });
    }
}
