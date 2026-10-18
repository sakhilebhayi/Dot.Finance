<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerReconciler;
use App\Services\TransferService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckBalancesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function codes(array $report): array
    {
        return array_values(array_unique(array_column($report['findings'], 'code')));
    }

    public function test_a_clean_ledger_reports_no_drift_and_exits_zero(): void
    {
        $user = User::factory()->create();
        $a = Account::factory()->for($user)->opening(100_000, '2026-01-01')->create(['name' => 'Reconciled current account']);
        $b = Account::factory()->for($user)->create();
        Transaction::factory()->for($a)->income()->cents(25_000)->on('2026-02-01')->create();
        Transaction::factory()->for($a)->expense()->cents(7_550)->on('2026-02-02')->create();
        app(TransferService::class)->create($user, $a, $b, 10_000, null, Carbon::parse('2026-02-03'), 'Pair', null);

        $this->artisan('finance:check-balances')
            ->expectsOutputToContain('Reconciled current account')
            ->expectsOutputToContain('No drift')
            ->assertExitCode(0);
    }

    public function test_the_independent_recompute_matches_the_sql_balance_for_a_mixed_ledger(): void
    {
        $user = User::factory()->create();
        $a = Account::factory()->for($user)->opening(-5_000, '2026-01-10')->create();
        $b = Account::factory()->for($user)->currency('USD')->opening(1_000, '2026-01-10')->create();
        Transaction::factory()->for($a)->income()->cents(30_001)->on('2026-01-10')->create();
        Transaction::factory()->for($a)->expense()->cents(1_234)->on('2026-03-01')->create();
        Transaction::factory()->for($a)->expense()->cents(9_999)->on('2026-01-09')->create(); // before the opening date
        Transaction::factory()->for($b)->expense()->cents(333)->on('2026-03-02')->create();

        $report = (new LedgerReconciler)->run(LedgerContext::personal($user));

        $this->assertSame([], $report['findings']);
        $byId = collect($report['accounts'])->keyBy('account_id');
        $this->assertSame(-5_000 + 30_001 - 1_234, $byId[$a->id]['derived_cents']);
        $this->assertSame($byId[$a->id]['derived_cents'], $byId[$a->id]['recomputed_cents']);
        $this->assertSame(0, $byId[$a->id]['drift_cents']);
        $this->assertSame(1_000 - 333, $byId[$b->id]['derived_cents']);
    }

    public function test_a_transaction_whose_currency_differs_from_its_account_is_flagged(): void
    {
        $account = Account::factory()->currency('ZAR')->create();
        $tx = Transaction::factory()->for($account)->create();
        DB::table('transactions')->where('id', $tx->id)->update(['currency' => 'USD']);

        $report = (new LedgerReconciler)->runAll();

        $this->assertContains('currency_mismatch', $this->codes($report));
        $this->artisan('finance:check-balances')->assertExitCode(1);
    }

    public function test_an_orphaned_transfer_leg_is_flagged(): void
    {
        $user = User::factory()->create();
        $a = Account::factory()->for($user)->create();
        $b = Account::factory()->for($user)->create();
        app(TransferService::class)->create($user, $a, $b, 1_000, null, Carbon::today(), 'Will be orphaned', null);
        DB::table('transactions')->where('transfer_direction', 'in')->delete();

        $report = (new LedgerReconciler)->run(LedgerContext::personal($user));

        $this->assertContains('orphan_transfer_leg', $this->codes($report));
    }

    public function test_deleted_transactions_are_ignored_by_both_the_sql_and_the_recompute(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->opening(10_000, null)->create();
        Transaction::factory()->for($account)->expense()->cents(2_500)->create();
        Transaction::factory()->for($account)->expense()->cents(9_999)->create()->delete();

        $report = (new LedgerReconciler)->run(LedgerContext::personal($user));

        $this->assertSame([], $report['findings']);
        $this->assertSame(7_500, $report['accounts'][0]['derived_cents']);
        $this->assertSame(7_500, $report['accounts'][0]['recomputed_cents']);
        $this->assertSame(1, $report['accounts'][0]['transactions_counted']);
    }

    public function test_a_transfer_leg_whose_peer_went_with_a_deleted_account_is_history_not_damage(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $closing = Account::factory()->for($user)->create();
        $keeping = Account::factory()->for($user)->create();
        app(TransferService::class)->create($user, $closing, $keeping, 1_000, null, Carbon::today(), 'Before closing', null);

        $this->actingAs($user)->delete(route('accounts.destroy', $closing), ['confirm' => '1']);

        $report = (new LedgerReconciler)->run(LedgerContext::personal($user));

        $this->assertContains('transfer_peer_deleted', $this->codes($report));
        $this->assertSame([], LedgerReconciler::problems($report));
    }

    public function test_a_transfer_pair_whose_legs_disagree_on_account_currency_is_flagged(): void
    {
        $user = User::factory()->create();
        $a = Account::factory()->for($user)->create();
        $b = Account::factory()->for($user)->create();
        app(TransferService::class)->create($user, $a, $b, 1_000, null, Carbon::today(), 'Pair that will drift', null);
        DB::table('transactions')->where('transfer_direction', 'in')->update(['amount_cents' => 999]);

        $report = (new LedgerReconciler)->run(LedgerContext::personal($user));

        $this->assertContains('transfer_amount_mismatch', $this->codes($report));
    }

    public function test_cents_that_disagree_with_the_legacy_decimal_mirror_are_flagged(): void
    {
        $account = Account::factory()->create();
        $tx = Transaction::factory()->for($account)->cents(1_000)->create();
        DB::table('transactions')->where('id', $tx->id)->update(['amount' => '99.99']);

        $this->assertContains('amount_mirror_mismatch', $this->codes((new LedgerReconciler)->runAll()));
    }

    public function test_an_unmigrated_legacy_row_is_flagged(): void
    {
        $account = Account::factory()->create();
        DB::table('transactions')->insert([
            'account_id' => $account->id, 'user_id' => $account->user_id, 'description' => 'never backfilled',
            'amount' => '10.00', 'amount_cents' => 0, 'currency' => null, 'type' => 'expense', 'date' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertContains('unmigrated_legacy_row', $this->codes((new LedgerReconciler)->runAll()));
    }

    public function test_legacy_transfers_without_a_destination_are_reported_but_do_not_fail_the_run(): void
    {
        $account = Account::factory()->create();
        Transaction::factory()->for($account)->cents(5_000)->create(['type' => 'transfer', 'transfer_direction' => null]);

        $report = (new LedgerReconciler)->runAll();

        $this->assertContains('legacy_transfer_without_destination', $this->codes($report));
        $this->artisan('finance:check-balances')->expectsOutputToContain('No drift')->assertExitCode(0);
    }

    public function test_the_user_option_limits_the_check_to_one_user(): void
    {
        $clean = User::factory()->create();
        $broken = User::factory()->create();
        Account::factory()->for($clean)->create();
        $tx = Transaction::factory()->for(Account::factory()->for($broken)->create())->create();
        DB::table('transactions')->where('id', $tx->id)->update(['currency' => 'USD']);

        $this->artisan('finance:check-balances', ['--user' => $clean->id])->assertExitCode(0);
        $this->artisan('finance:check-balances', ['--user' => $broken->id])->assertExitCode(1);
    }

    public function test_the_reconciliation_page_shows_the_users_own_accounts_only(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->create();
        Account::factory()->for($user)->opening(12_345_67, null)->create(['name' => 'Page reconciliation mine']);
        Account::factory()->for($other)->create(['name' => 'Page reconciliation theirs']);

        $this->actingAs($user)->get(route('reconciliation.index'))->assertOk()
            ->assertSee('Page reconciliation mine')
            ->assertSee('ZAR 12,345.67')
            ->assertDontSee('Page reconciliation theirs');
    }
}
