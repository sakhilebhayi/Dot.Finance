<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LegacyRecurrenceBackfill;
use App\Services\TransactionRecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecurringRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rule(User $user, array $overrides = []): RecurringRule
    {
        $account = $overrides['account'] ?? Account::factory()->for($user)->create();
        unset($overrides['account']);

        return RecurringRule::factory()->for($user)->create(array_merge(['account_id' => $account->id], $overrides));
    }

    private function runScheduler(): int
    {
        return app(TransactionRecurrenceService::class)->generateDueOccurrences();
    }

    // ── creation ───────────────────────────────────────────────────────────

    public function test_creating_a_transaction_with_a_repeat_creates_a_rule_and_links_the_first_entry(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id, 'description' => 'Rent', 'amount' => '1200.00', 'type' => 'expense',
            'date' => '2026-08-01', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1,
        ])->assertRedirect();

        $tx = Transaction::where('description', 'Rent')->firstOrFail();
        $rule = RecurringRule::firstOrFail();

        $this->assertSame($rule->id, $tx->recurring_rule_id);
        $this->assertTrue($tx->isRecurring());
        $this->assertSame('monthly', $rule->recurrence_type);
        $this->assertSame(120_000, $rule->amount_cents);
        $this->assertSame('2026-08-01', $rule->start_date->toDateString());
        $this->assertSame('2026-09-01', $rule->next_occurrence_date->toDateString());
        $this->assertSame('ZAR', $rule->currency);
    }

    public function test_a_transaction_without_a_repeat_creates_no_rule(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id, 'description' => 'One-off', 'amount' => '50.00', 'type' => 'expense', 'date' => '2026-08-01',
        ]);

        $this->assertSame(0, RecurringRule::count());
        $this->assertFalse(Transaction::firstOrFail()->isRecurring());
    }

    // ── generation ─────────────────────────────────────────────────────────

    public function test_a_due_rule_creates_an_entry_and_advances(): void
    {
        Carbon::setTestNow('2026-09-01');
        $user = User::factory()->create();
        $rule = $this->rule($user, ['amount_cents' => 120_000, 'start_date' => '2026-08-01', 'next_occurrence_date' => '2026-09-01']);

        $this->assertSame(1, $this->runScheduler());

        $tx = Transaction::where('recurring_rule_id', $rule->id)->sole();
        $this->assertSame('2026-09-01', $tx->date->toDateString());
        $this->assertSame(120_000, $tx->amount_cents);
        $this->assertSame('expense', $tx->type);
        $this->assertSame('2026-10-01', $rule->fresh()->next_occurrence_date->toDateString());
    }

    public function test_nothing_is_generated_before_the_due_date(): void
    {
        Carbon::setTestNow('2026-08-15');
        $this->rule(User::factory()->create(), ['start_date' => '2026-08-01', 'next_occurrence_date' => '2026-09-01']);

        $this->assertSame(0, $this->runScheduler());
        $this->assertSame(0, Transaction::count());
    }

    public function test_missed_periods_are_caught_up_in_one_run(): void
    {
        Carbon::setTestNow('2026-11-01');
        $rule = $this->rule(User::factory()->create(), ['start_date' => '2026-08-01', 'next_occurrence_date' => '2026-09-01']);

        $this->assertSame(3, $this->runScheduler()); // Sep, Oct, Nov
        $this->assertSame('2026-12-01', $rule->fresh()->next_occurrence_date->toDateString());
    }

    public function test_running_twice_on_the_same_day_does_not_double_generate(): void
    {
        Carbon::setTestNow('2026-09-01');
        $this->rule(User::factory()->create(), ['start_date' => '2026-08-01', 'next_occurrence_date' => '2026-09-01']);

        $this->assertSame(1, $this->runScheduler());
        $this->assertSame(0, $this->runScheduler());
        $this->assertSame(1, Transaction::count());
    }

    public function test_monthly_dates_do_not_drift_after_a_short_month(): void
    {
        Carbon::setTestNow('2026-04-30');
        $rule = $this->rule(User::factory()->create(), ['start_date' => '2026-01-31', 'next_occurrence_date' => '2026-02-28']);

        $this->assertSame(3, $this->runScheduler());

        $dates = Transaction::where('recurring_rule_id', $rule->id)->orderBy('date')->get()->map(fn ($t) => $t->date->toDateString())->all();
        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30'], $dates);
        $this->assertSame('2026-05-31', $rule->fresh()->next_occurrence_date->toDateString());
    }

    public function test_an_entry_generated_by_a_rule_moves_the_balance(): void
    {
        Carbon::setTestNow('2026-09-01');
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->opening(100_000, null)->create();
        $this->rule($user, ['account' => $account, 'amount_cents' => 25_000, 'start_date' => '2026-08-01', 'next_occurrence_date' => '2026-09-01']);

        $this->runScheduler();

        $this->assertSame(75_000, Account::withoutGlobalScopes()->withBalance()->findOrFail($account->id)->balanceCents());
    }

    public function test_a_rule_on_a_deleted_account_generates_nothing(): void
    {
        Carbon::setTestNow('2026-09-01');
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $this->rule($user, ['account' => $account, 'start_date' => '2026-08-01', 'next_occurrence_date' => '2026-09-01']);
        $account->delete();

        $this->assertSame(0, $this->runScheduler());
    }

    // ── pause / resume / stop ──────────────────────────────────────────────

    public function test_a_paused_rule_generates_nothing_and_resuming_skips_the_missed_periods(): void
    {
        Carbon::setTestNow('2026-09-01');
        $user = User::factory()->withPersonalTeam()->create();
        $rule = $this->rule($user, ['start_date' => '2026-08-01', 'next_occurrence_date' => '2026-09-01']);

        $this->actingAs($user)->post(route('recurring.pause', $rule))->assertRedirect(route('recurring.index'));
        $this->assertNotNull($rule->fresh()->paused_at);

        Carbon::setTestNow('2026-12-10');
        $this->assertSame(0, $this->runScheduler());

        $this->actingAs($user)->post(route('recurring.resume', $rule))->assertRedirect(route('recurring.index'));

        $rule->refresh();
        $this->assertNull($rule->paused_at);
        // first on-cadence date from today forward; Sep, Oct, Nov, Dec 1st are not back-filled
        $this->assertSame('2027-01-01', $rule->next_occurrence_date->toDateString());
        $this->assertSame(0, Transaction::count());
    }

    public function test_stopping_a_rule_now_ends_it_with_an_end_date_and_no_next_occurrence(): void
    {
        Carbon::setTestNow('2026-09-10');
        $user = User::factory()->withPersonalTeam()->create();
        $rule = $this->rule($user, ['start_date' => '2026-08-01', 'next_occurrence_date' => '2026-10-01']);

        $this->actingAs($user)->post(route('recurring.stop', $rule))->assertRedirect(route('recurring.index'));

        $rule->refresh();
        $this->assertSame('2026-09-10', $rule->end_date->toDateString());
        $this->assertNull($rule->next_occurrence_date);
        $this->assertNotNull($rule->ended_at);

        Carbon::setTestNow('2026-12-01');
        $this->assertSame(0, $this->runScheduler());
    }

    public function test_an_end_date_is_inclusive_and_ends_the_series_after_it(): void
    {
        Carbon::setTestNow('2026-12-15');
        $user = User::factory()->withPersonalTeam()->create();
        $rule = $this->rule($user, ['start_date' => '2026-08-01', 'next_occurrence_date' => '2026-09-01']);

        $this->actingAs($user)->post(route('recurring.stop', $rule), ['end_date' => '2026-10-01'])->assertRedirect();

        $this->assertSame(2, $this->runScheduler()); // Sep 1 and Oct 1 only
        $rule->refresh();
        $this->assertNull($rule->next_occurrence_date);
        $this->assertSame('2026-10-01', $rule->end_date->toDateString());
        $this->assertSame(2, Transaction::where('recurring_rule_id', $rule->id)->count());
    }

    // ── editing ────────────────────────────────────────────────────────────

    public function test_editing_a_rule_changes_future_entries_and_leaves_history_alone(): void
    {
        Carbon::setTestNow('2026-09-01');
        $user = User::factory()->withPersonalTeam()->create();
        $rule = $this->rule($user, ['amount_cents' => 100_000, 'start_date' => '2026-08-01', 'next_occurrence_date' => '2026-09-01']);
        $this->runScheduler();
        $first = Transaction::where('recurring_rule_id', $rule->id)->sole();

        $this->actingAs($user)->get(route('recurring.edit', $rule))->assertOk();
        $this->actingAs($user)->put(route('recurring.update', $rule), [
            'description' => 'Rent (new lease)', 'amount' => '1500.00', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1,
        ])->assertRedirect(route('recurring.index'));

        Carbon::setTestNow('2026-10-01');
        $this->runScheduler();

        $this->assertSame(100_000, $first->fresh()->amount_cents);
        $second = Transaction::where('recurring_rule_id', $rule->id)->orderByDesc('date')->first();
        $this->assertSame('2026-10-01', $second->date->toDateString());
        $this->assertSame(150_000, $second->amount_cents);
        $this->assertSame('Rent (new lease)', $second->description);
    }

    public function test_changing_the_cadence_recomputes_the_next_occurrence(): void
    {
        Carbon::setTestNow('2026-09-10');
        $user = User::factory()->withPersonalTeam()->create();
        $rule = $this->rule($user, ['start_date' => '2026-08-01', 'next_occurrence_date' => '2026-10-01']);
        // the September entry was generated on the 1st
        Transaction::factory()->for($rule->account)->create(['recurring_rule_id' => $rule->id, 'date' => '2026-09-01']);

        $this->actingAs($user)->put(route('recurring.update', $rule), [
            'description' => $rule->description, 'amount' => '1200.00', 'recurrence_type' => 'weekly', 'recurrence_interval' => 1,
        ])->assertRedirect();

        $rule->refresh();
        $this->assertSame('weekly', $rule->recurrence_type);
        // weekly from the last generated entry (Sep 1): the 8th is already past, so the next one is the 15th
        $this->assertSame('2026-09-15', $rule->next_occurrence_date->toDateString());
        $this->assertSame('2026-09-01', $rule->start_date->toDateString());
    }

    public function test_a_rules_category_must_match_its_type(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $rule = $this->rule($user, ['type' => 'expense']);
        $salary = Category::factory()->for($user)->income()->create();

        $this->actingAs($user)->put(route('recurring.update', $rule), [
            'description' => 'x', 'amount' => '10.00', 'category_id' => $salary->id, 'recurrence_type' => 'monthly', 'recurrence_interval' => 1,
        ])->assertSessionHasErrors('category_id');
    }

    public function test_the_list_shows_each_rules_status(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->rule($user, ['description' => 'Active rule description', 'next_occurrence_date' => '2026-09-01']);
        $this->rule($user, ['description' => 'Paused rule description', 'paused_at' => now()]);
        $this->rule($user, ['description' => 'Ended rule description', 'next_occurrence_date' => null, 'ended_at' => now(), 'end_date' => '2026-01-01']);

        $this->actingAs($user)->get(route('recurring.index'))->assertOk()
            ->assertSee('Active rule description')
            ->assertSee('Paused rule description')
            ->assertSee('Ended rule description')
            ->assertSee('Paused')
            ->assertSee('Ended');
    }

    public function test_one_user_cannot_see_or_change_another_users_rule(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->withPersonalTeam()->create();
        $rule = $this->rule($owner, ['description' => 'Owner private rule']);

        $this->actingAs($attacker)->get(route('recurring.index'))->assertOk()->assertDontSee('Owner private rule');
        $this->actingAs($attacker)->get(route('recurring.edit', $rule))->assertNotFound();
        $this->actingAs($attacker)->post(route('recurring.pause', $rule))->assertNotFound();
        $this->actingAs($attacker)->post(route('recurring.stop', $rule))->assertNotFound();
        $this->actingAs($attacker)->put(route('recurring.update', $rule), ['description' => 'x', 'amount' => '1.00', 'recurrence_type' => 'daily', 'recurrence_interval' => 1])->assertNotFound();

        $this->assertNull($rule->fresh()->paused_at);
    }

    // ── legacy anchors ─────────────────────────────────────────────────────

    public function test_legacy_anchor_transactions_are_converted_into_rules(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $anchor = Transaction::factory()->for($account)->expense()->cents(120_000)->on('2026-08-01')->create([
            'description' => 'Legacy rent', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'next_occurrence_date' => '2026-10-01',
        ]);
        $child = Transaction::factory()->for($account)->expense()->cents(120_000)->on('2026-09-01')->create([
            'description' => 'Legacy rent', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'recurrence_parent_id' => $anchor->id,
        ]);
        $plain = Transaction::factory()->for($account)->create();

        $report = (new LegacyRecurrenceBackfill)->run();

        $this->assertSame(1, $report['rules']);
        $rule = RecurringRule::firstOrFail();
        $this->assertSame('Legacy rent', $rule->description);
        $this->assertSame(120_000, $rule->amount_cents);
        $this->assertSame('2026-08-01', $rule->start_date->toDateString());
        $this->assertSame('2026-10-01', $rule->next_occurrence_date->toDateString());
        $this->assertSame($rule->id, $anchor->fresh()->recurring_rule_id);
        $this->assertSame($rule->id, $child->fresh()->recurring_rule_id);
        $this->assertNull($plain->fresh()->recurring_rule_id);

        // idempotent
        $this->assertSame(0, (new LegacyRecurrenceBackfill)->run()['rules']);
        $this->assertSame(1, RecurringRule::count());
    }

    public function test_the_new_schedule_never_reads_the_old_anchor_columns(): void
    {
        Carbon::setTestNow('2026-09-01');
        $account = Account::factory()->create();
        Transaction::factory()->for($account)->create([
            'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'next_occurrence_date' => '2026-09-01',
        ]);

        $this->assertSame(0, $this->runScheduler());
        $this->assertSame(1, DB::table('transactions')->count());
    }
}
