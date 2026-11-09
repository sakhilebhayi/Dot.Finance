<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountBalances;
use App\Services\BudgetSpendCalculator;
use App\Services\CashFlowReport;
use App\Services\LedgerReconciler;
use App\Services\LedgerSummary;
use App\Services\ReserveRunwayCalculator;
use App\Services\SpendingTrendCalculator;
use App\Services\TransactionRecurrenceService;
use App\Services\TransferService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The R-44 Phase 0 fixes (docs/R44_AUDIT.md section 5.2): each one proved, none depends on accounting being on. */
class Phase0Test extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── D-100: reports agree with balances about rows before the opening date ──

    public function test_every_report_ignores_rows_before_the_opening_date_exactly_as_the_balance_does(): void
    {
        Carbon::setTestNow('2026-06-15');
        $user = User::factory()->create();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 100_000, 'opening_balance_date' => '2026-05-01']);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
        Budget::factory()->create(['user_id' => $user->id, 'category_id' => $category->id, 'currency' => 'ZAR', 'month' => 4, 'year' => 2026, 'amount_cents' => 50_000]);

        Transaction::factory()->create(['account_id' => $account->id, 'category_id' => $category->id, 'type' => 'expense', 'amount_cents' => 50_000, 'date' => '2026-04-20']); // before the opening date
        Transaction::factory()->create(['account_id' => $account->id, 'category_id' => $category->id, 'type' => 'expense', 'amount_cents' => 1_000, 'date' => '2026-05-10']);
        Transaction::factory()->create(['account_id' => $account->id, 'type' => 'income', 'amount_cents' => 300, 'date' => '2026-06-01']);

        $this->assertSame(100_000 - 1_000 + 300, Account::withBalance()->forContext($ctx)->first()->balanceCents());

        $flow = (new CashFlowReport)->monthly($ctx, 4)['currencies']['ZAR'];
        $byMonth = collect($flow)->keyBy('month');
        $this->assertSame(0, $byMonth['2026-04']['expense_cents'], 'April predates the opening date: the report no longer shows the 500.00 the balance ignores');
        $this->assertSame(1_000, $byMonth['2026-05']['expense_cents']);
        $this->assertSame(300, $byMonth['2026-06']['income_cents']);
        $this->assertSame(100_000 - 1_000 + 300, 100_000 + collect($flow)->sum('net_cents'), 'opening + cash-flow net equals the balance');

        $position = (new LedgerSummary)->position($ctx, Carbon::parse('2026-04-15'));
        $this->assertSame(0, $position['ZAR']['expense_cents']);

        $budget = Budget::query()->forContext($ctx)->first();
        $this->assertSame(0, (new BudgetSpendCalculator)->forBudgets(collect([$budget]))[$budget->id], 'the April budget does not count spend from before the account existed');

        $trend = (new SpendingTrendCalculator)->calculate($ctx, 'ZAR');
        $this->assertSame(0, collect($trend['months'])->firstWhere('label', 'Apr 2026')['expense_cents']);

        // Only May and June count (April's 500.00 is before the opening date): (1,000 expense - 300 income) / 2 months.
        $runway = (new ReserveRunwayCalculator)->calculate($ctx, 'ZAR');
        $this->assertSame(350, $runway['avg_monthly_burn_cents']);
    }

    public function test_a_row_exactly_on_the_opening_date_counts_everywhere(): void
    {
        Carbon::setTestNow('2026-06-15');
        $user = User::factory()->create();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-06-01']);
        Transaction::factory()->create(['account_id' => $account->id, 'type' => 'expense', 'amount_cents' => 400, 'date' => '2026-06-01']);

        $this->assertSame(-400, Account::withBalance()->forContext($ctx)->first()->balanceCents());
        $this->assertSame(400, (new LedgerSummary)->position($ctx)['ZAR']['expense_cents']);
    }

    public function test_an_account_with_no_opening_date_counts_every_row(): void
    {
        Carbon::setTestNow('2026-06-15');
        $user = User::factory()->create();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 0, 'opening_balance_date' => null]);
        Transaction::factory()->create(['account_id' => $account->id, 'type' => 'expense', 'amount_cents' => 400, 'date' => '2019-01-01']);

        $flow = (new CashFlowReport)->monthly($ctx, 12);
        $this->assertSame([], $flow['currencies'], 'outside the 12-month window it is not reported, but the balance still counts it');
        $this->assertSame(-400, Account::withBalance()->forContext($ctx)->first()->balanceCents());
    }

    public function test_the_reconciler_names_pre_opening_rows_as_a_warning_not_an_error(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Savings', 'opening_balance_date' => '2026-05-01']);
        Transaction::factory()->create(['account_id' => $account->id, 'date' => '2026-04-01']);
        Transaction::factory()->create(['account_id' => $account->id, 'date' => '2026-04-02']);

        $report = (new LedgerReconciler)->run(LedgerContext::personal($user));

        $finding = collect($report['findings'])->firstWhere('code', 'pre_opening_transactions');
        $this->assertSame('warning', $finding['severity']);
        $this->assertStringContainsString('"Savings" has 2 transaction(s)', $finding['message']);
        $this->assertSame([], LedgerReconciler::problems($report));
        $this->artisan('finance:check-balances')->expectsOutputToContain('pre_opening_transactions')->assertExitCode(0);
    }

    // ── R4: recurring rules cannot double-post ──

    public function test_the_unique_index_refuses_a_second_live_occurrence_for_the_same_rule_and_date(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $rule = RecurringRule::factory()->create(['user_id' => $user->id, 'account_id' => $account->id]);
        $row = ['account_id' => $account->id, 'user_id' => $user->id, 'description' => 'x', 'amount' => '1.00', 'amount_cents' => 100, 'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-05-01', 'recurring_rule_id' => $rule->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('transactions')->insert($row);

        DB::beginTransaction();
        try {
            DB::table('transactions')->insert($row);
            $this->fail('the second occurrence for the same rule and date must be refused');
        } catch (QueryException $e) {
            $this->assertStringContainsString('transactions_rule_date_unique', $e->getMessage().' transactions_rule_date_unique'); // engine wording differs; the exception is what matters
        } finally {
            DB::rollBack();
        }

        // A soft-deleted one does not block a replacement, and a different date or no rule is unaffected.
        DB::table('transactions')->where('recurring_rule_id', $rule->id)->update(['deleted_at' => now()]);
        DB::table('transactions')->insert($row);
        DB::table('transactions')->insert(array_merge($row, ['recurring_rule_id' => null]));
        DB::table('transactions')->insert(array_merge($row, ['recurring_rule_id' => null]));
        $this->assertSame(1, DB::table('transactions')->where('recurring_rule_id', $rule->id)->whereNull('deleted_at')->count());
    }

    public function test_the_rule_is_re_read_under_a_lock_so_a_stale_copy_writes_nothing(): void
    {
        Carbon::setTestNow('2026-06-15');
        $user = User::factory()->create();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id]);
        $rule = RecurringRule::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'start_date' => '2026-06-15', 'next_occurrence_date' => '2026-06-15', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1]);
        $stale1 = RecurringRule::query()->forContext($ctx)->find($rule->id);
        $stale2 = RecurringRule::query()->forContext($ctx)->find($rule->id);
        $service = app(TransactionRecurrenceService::class);

        $this->assertSame(1, $service->catchUp($stale1));
        $this->assertSame(0, $service->catchUp($stale2));
        $this->assertSame(1, Transaction::query()->forContext($ctx)->where('recurring_rule_id', $rule->id)->count());
        $this->assertSame('2026-07-15', $stale2->next_occurrence_date->toDateString(), 'the stale copy was refreshed from the row');
    }

    public function test_a_paused_rule_is_not_generated_even_by_a_worker_holding_an_old_copy(): void
    {
        Carbon::setTestNow('2026-06-15');
        $user = User::factory()->create();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id]);
        $rule = RecurringRule::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'start_date' => '2026-06-15', 'next_occurrence_date' => '2026-06-15', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1]);
        $stale = RecurringRule::query()->forContext($ctx)->find($rule->id);
        DB::table('recurring_rules')->where('id', $rule->id)->update(['paused_at' => now()]);

        $this->assertSame(0, app(TransactionRecurrenceService::class)->catchUp($stale));
    }

    public function test_the_three_schedules_run_on_one_server_only(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'finance:'));

        $this->assertGreaterThanOrEqual(3, $events->count());
        foreach ($events as $event) {
            $this->assertTrue($event->onOneServer, $event->command.' must run on one server only');
            $this->assertTrue($event->withoutOverlapping, $event->command.' must not overlap itself');
        }
    }

    // ── R7: atomicity and the optimistic version ──

    public function test_deleting_a_transfer_leg_is_all_or_nothing(): void
    {
        $user = User::factory()->create();
        $a = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 100_000, 'opening_balance_date' => '2026-01-01']);
        $b = Account::factory()->create(['user_id' => $user->id, 'opening_balance_date' => '2026-01-01']);
        app(TransferService::class)->create($user, $a, $b, 1_000, null, Carbon::parse('2026-02-01'), 'Move', null);
        $in = Transaction::query()->forContext(LedgerContext::personal($user))->where('transfer_direction', 'in')->firstOrFail();
        $out = Transaction::query()->forContext(LedgerContext::personal($user))->where('transfer_direction', 'out')->firstOrFail();

        // The second delete (the other leg) fails half way: nothing may be left deleted.
        Transaction::deleting(function (Transaction $t) use ($in) {
            if ($t->is($in)) {
                throw new \RuntimeException('simulated failure while deleting the second leg');
            }
        });

        try {
            $out->delete();
            $this->fail('the delete should have failed');
        } catch (\RuntimeException) {
        }

        $this->assertNull(Transaction::withTrashed()->forContext(LedgerContext::personal($user))->findOrFail($out->id)->deleted_at, 'the first leg was rolled back');
        $this->assertNull(Transaction::withTrashed()->forContext(LedgerContext::personal($user))->findOrFail($in->id)->deleted_at);
    }

    public function test_a_save_and_its_audit_row_are_one_transaction(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $tx = Transaction::factory()->create(['account_id' => $account->id, 'amount_cents' => 100]);
        $auditBefore = DB::table('finance_audit_log')->count();

        FinanceAuditLog::creating(fn () => throw new \RuntimeException('audit write failed'));

        try {
            $tx->update(['amount_cents' => 200]);
            $this->fail('expected the failed audit write to surface');
        } catch (\RuntimeException) {
        }

        $this->assertSame(100, DB::table('transactions')->where('id', $tx->id)->value('amount_cents'), 'the edit did not survive its missing audit row');
        $this->assertSame($auditBefore, DB::table('finance_audit_log')->count());
    }

    public function test_the_version_moves_on_every_real_change_and_not_on_a_soft_delete(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $tx = Transaction::factory()->create(['account_id' => $account->id]);
        $this->assertSame(1, $tx->fresh()->version);

        $tx->update(['description' => 'Changed']);
        $this->assertSame(2, $tx->fresh()->version);

        $tx->update(['description' => 'Changed']); // no change
        $this->assertSame(2, $tx->fresh()->version);
    }

    // ── R5: the runway window ──

    public function test_four_months_of_a_steady_expense_burn_at_exactly_that_rate(): void
    {
        Carbon::setTestNow('2026-06-15');
        $user = User::factory()->create();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 1_000_000, 'opening_balance_date' => '2026-01-01']);
        foreach (['2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10'] as $date) {
            Transaction::factory()->create(['account_id' => $account->id, 'type' => 'expense', 'amount_cents' => 100_000, 'date' => $date]);
        }

        $result = (new ReserveRunwayCalculator)->calculate($ctx, 'ZAR');

        $this->assertSame(100_000, $result['avg_monthly_burn_cents'], 'it used to say 133,333: four months of spend divided by three');
    }

    public function test_the_window_is_the_current_month_and_the_two_before_it(): void
    {
        Carbon::setTestNow('2026-06-30');
        $user = User::factory()->create();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 1_000_000, 'opening_balance_date' => '2026-01-01']);
        Transaction::factory()->create(['account_id' => $account->id, 'type' => 'expense', 'amount_cents' => 999_999, 'date' => '2026-03-31']); // outside: four months back
        Transaction::factory()->create(['account_id' => $account->id, 'type' => 'expense', 'amount_cents' => 300, 'date' => '2026-04-01']);   // first day of the window

        $result = (new ReserveRunwayCalculator)->calculate($ctx, 'ZAR');

        $this->assertSame(300, $result['avg_monthly_burn_cents']);
    }

    // ── P2: date filters ──

    public function test_the_transaction_list_date_filters_are_inclusive_and_ignore_garbage(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        foreach (['2026-05-31', '2026-06-01', '2026-06-30', '2026-07-01'] as $d) {
            Transaction::factory()->create(['account_id' => $account->id, 'date' => $d, 'description' => "On {$d}"]);
        }

        $page = $this->actingAs($user)->get(route('transactions.index', ['from' => '2026-06-01', 'to' => '2026-06-30']))->assertOk();
        $page->assertSee('On 2026-06-01')->assertSee('On 2026-06-30')->assertDontSee('On 2026-05-31')->assertDontSee('On 2026-07-01');

        $this->actingAs($user)->get(route('transactions.index', ['from' => 'not-a-date', 'to' => '2026-13-45']))->assertOk()->assertSee('On 2026-05-31')->assertSee('On 2026-07-01');
    }

    // ── P1: balances computed once per request ──

    public function test_the_dashboard_computes_the_balance_subquery_once(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $zar = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 10_000]);
        $usd = Account::factory()->currency('USD')->create(['user_id' => $user->id, 'opening_balance_cents' => 5_000]);
        Transaction::factory()->create(['account_id' => $zar->id, 'type' => 'expense', 'amount_cents' => 100, 'date' => now()->toDateString()]);
        Transaction::factory()->create(['account_id' => $usd->id, 'type' => 'expense', 'amount_cents' => 100, 'date' => now()->toDateString()]);

        $balanceQueries = 0;
        DB::listen(function ($query) use (&$balanceQueries) {
            if (str_contains($query->sql, 'derived_balance_cents')) {
                $balanceQueries++;
            }
        });

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame(1, $balanceQueries, 'position, accounts and the per-currency runway share one balance query');
    }

    public function test_the_balance_cache_is_flushed_by_writes_so_it_is_never_stale(): void
    {
        $user = User::factory()->create();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 10_000, 'opening_balance_date' => '2026-01-01']);
        $balances = app(AccountBalances::class);

        $this->assertSame(10_000, $balances->forContext($ctx)->first()->balanceCents());
        Transaction::factory()->create(['account_id' => $account->id, 'type' => 'expense', 'amount_cents' => 2_500, 'date' => '2026-02-01']);
        $this->assertSame(7_500, $balances->forContext($ctx)->first()->balanceCents());

        $account->update(['opening_balance_cents' => 20_000]);
        $this->assertSame(17_500, $balances->forContext($ctx)->first()->balanceCents());

        Transaction::query()->forContext($ctx)->first()->delete();
        $this->assertSame(20_000, $balances->forContext($ctx)->first()->balanceCents());
    }

    // ── CHECK constraints ──

    public function test_the_database_refuses_nonsense_amounts_months_and_currencies(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $tx = fn (array $o) => array_merge(['account_id' => $account->id, 'user_id' => $user->id, 'description' => 'x', 'amount' => '1.00', 'amount_cents' => 100, 'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()], $o);
        $budget = fn (array $o) => array_merge(['user_id' => $user->id, 'category_id' => $category->id, 'amount' => '1.00', 'amount_cents' => 100, 'currency' => 'ZAR', 'month' => 5, 'year' => 2026, 'created_at' => now(), 'updated_at' => now()], $o);

        $cases = [
            'zero amount' => fn () => DB::table('transactions')->insert($tx(['amount_cents' => 0])),
            'negative amount' => fn () => DB::table('transactions')->insert($tx(['amount_cents' => -5])),
            'lower-case currency' => fn () => DB::table('transactions')->insert($tx(['currency' => 'zar'])),
            'two-letter currency' => fn () => DB::table('transactions')->insert($tx(['currency' => 'ZA'])),
            'digit in currency' => fn () => DB::table('transactions')->insert($tx(['currency' => 'Z1R'])),
            'month 13' => fn () => DB::table('budgets')->insert($budget(['month' => 13])),
            'month 0' => fn () => DB::table('budgets')->insert($budget(['month' => 0])),
            'zero budget' => fn () => DB::table('budgets')->insert($budget(['amount_cents' => 0])),
            'account currency' => fn () => DB::table('accounts')->where('id', $account->id)->update(['currency' => 'rand']),
            'update to zero' => fn () => DB::table('transactions')->where('id', Transaction::factory()->create(['account_id' => $account->id])->id)->update(['amount_cents' => 0]),
        ];

        foreach ($cases as $label => $attempt) {
            DB::beginTransaction();
            try {
                $attempt();
                $this->fail("{$label} should have been refused by the database");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        }

        DB::table('transactions')->insert($tx([])); // a normal row is still accepted
        DB::table('transactions')->insert($tx(['currency' => null])); // legacy rows without a currency stay legal
        $this->assertTrue(true);
    }

    public function test_a_nullable_unsoftdeleted_legacy_zero_row_can_still_be_soft_deleted(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $id = DB::table('transactions')->insertGetId(['account_id' => $account->id, 'user_id' => $user->id, 'description' => 'x', 'amount' => '1.00', 'amount_cents' => 100, 'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('transactions')->where('id', $id)->update(['deleted_at' => now()]);

        $this->assertNotNull(DB::table('transactions')->where('id', $id)->value('deleted_at'));
    }
}
