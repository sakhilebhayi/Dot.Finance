<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ForecastService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rule(User $user, Account $account, array $overrides = []): RecurringRule
    {
        return RecurringRule::factory()->for($user)->create(array_merge([
            'account_id' => $account->id, 'amount_cents' => 10_000, 'type' => 'expense',
            'recurrence_type' => 'monthly', 'start_date' => '2026-09-15', 'next_occurrence_date' => '2026-10-15',
        ], $overrides));
    }

    public function test_it_projects_the_balance_from_recurring_items_only(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->opening(100_000, null)->create();
        // A big one-off expense in the ledger: it is history, not a projection input.
        Transaction::factory()->for($account)->expense()->cents(20_000)->on('2026-09-20')->create();
        $this->rule($user, $account, ['amount_cents' => 10_000]);                                            // -100 monthly
        $this->rule($user, $account, ['amount_cents' => 50_000, 'type' => 'income', 'next_occurrence_date' => '2026-10-25', 'start_date' => '2026-09-25']); // +500 monthly

        $forecast = (new ForecastService)->project(LedgerContext::personal($user));
        $zar = $forecast['currencies']['ZAR'];

        $this->assertSame(80_000, $zar['start_cents'], 'the starting point is the actual ledger balance');
        // Within 90 days from 1 Oct: expense on Oct 15, Nov 15, Dec 15 (3); income on Oct 25, Nov 25, Dec 25 (3).
        $this->assertCount(6, $zar['events']);
        $this->assertSame(3 * 50_000, $zar['income_cents']);
        $this->assertSame(3 * 10_000, $zar['expense_cents']);
        $this->assertSame(80_000 + 150_000 - 30_000, $zar['end_cents']);
        $this->assertSame('2026-10-15', $zar['events'][0]['date']);
        $this->assertSame(70_000, $zar['events'][0]['balance_cents']);
    }

    public function test_the_projection_never_writes_to_the_ledger(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $this->rule($user, $account);
        $before = Transaction::withoutGlobalScopes()->count();

        (new ForecastService)->project(LedgerContext::personal($user));

        $this->assertSame($before, Transaction::withoutGlobalScopes()->count());
    }

    public function test_paused_ended_and_deleted_items_are_left_out_and_the_paused_ones_are_named(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $this->rule($user, $account, ['description' => 'Active item', 'amount_cents' => 1_000]);
        $this->rule($user, $account, ['description' => 'Paused item', 'amount_cents' => 2_000, 'paused_at' => now()]);
        $this->rule($user, $account, ['description' => 'Ended item', 'amount_cents' => 4_000, 'next_occurrence_date' => null, 'ended_at' => now()]);
        $this->rule($user, $account, ['description' => 'Deleted item', 'amount_cents' => 8_000])->delete();
        $deletedAccount = Account::factory()->for($user)->create();
        $this->rule($user, $deletedAccount, ['description' => 'On a deleted account', 'amount_cents' => 16_000]);
        $deletedAccount->delete();

        $forecast = (new ForecastService)->project(LedgerContext::personal($user));

        $this->assertSame(3 * 1_000, $forecast['currencies']['ZAR']['expense_cents']);
        $this->assertSame(['Paused item'], array_column($forecast['excluded'], 'description'));
        $this->assertSame(1, $forecast['included_rules']);
    }

    public function test_an_end_date_stops_the_projection_inclusively(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $this->rule($user, $account, ['amount_cents' => 1_000, 'next_occurrence_date' => '2026-10-15', 'end_date' => '2026-11-15']);

        $zar = (new ForecastService)->project(LedgerContext::personal($user))['currencies']['ZAR'];

        $this->assertSame(['2026-10-15', '2026-11-15'], array_column($zar['events'], 'date'));
    }

    public function test_a_due_but_not_yet_generated_occurrence_counts_from_today(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $this->rule($user, $account, ['amount_cents' => 5_000, 'start_date' => '2026-09-28', 'next_occurrence_date' => '2026-09-28']);

        $events = (new ForecastService)->project(LedgerContext::personal($user))['currencies']['ZAR']['events'];

        $this->assertSame('2026-10-01', $events[0]['date']);
        $this->assertTrue($events[0]['overdue']);
        $this->assertSame('2026-10-28', $events[1]['date']);
    }

    public function test_the_horizon_is_ninety_days_and_weekly_items_repeat_within_it(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $this->rule($user, $account, ['amount_cents' => 100, 'recurrence_type' => 'weekly', 'start_date' => '2026-10-02', 'next_occurrence_date' => '2026-10-02']);

        $forecast = (new ForecastService)->project(LedgerContext::personal($user));
        $events = $forecast['currencies']['ZAR']['events'];

        $this->assertSame('2026-10-01', $forecast['from']);
        $this->assertSame('2026-12-30', $forecast['to']);
        $this->assertCount(13, $events);
        $this->assertSame('2026-12-25', end($events)['date']);
    }

    public function test_month_end_items_do_not_drift(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $this->rule($user, $account, ['amount_cents' => 100, 'start_date' => '2026-08-31', 'next_occurrence_date' => '2026-10-31']);

        $dates = array_column((new ForecastService)->project(LedgerContext::personal($user))['currencies']['ZAR']['events'], 'date');

        $this->assertSame(['2026-10-31', '2026-11-30'], $dates);
    }

    public function test_currencies_are_projected_separately(): void
    {
        $user = User::factory()->create();
        $zar = Account::factory()->for($user)->currency('ZAR')->opening(100_000, null)->create();
        $usd = Account::factory()->for($user)->currency('USD')->opening(5_000, null)->create();
        $this->rule($user, $zar, ['amount_cents' => 10_000]);
        $this->rule($user, $usd, ['amount_cents' => 1_000]);

        $forecast = (new ForecastService)->project(LedgerContext::personal($user));

        $this->assertSame(['ZAR', 'USD'], array_keys($forecast['currencies']), 'the currency holding the most cash comes first');
        $this->assertSame(5_000 - 3_000, $forecast['currencies']['USD']['end_cents']);
        $this->assertSame(100_000 - 30_000, $forecast['currencies']['ZAR']['end_cents']);
    }

    public function test_a_credit_account_is_never_netted_against_cash_and_its_rules_are_left_out(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $cash = Account::factory()->for($user)->currency('USD')->opening(500_000, null)->create(['name' => 'Wise']);
        $card = Account::factory()->for($user)->currency('USD')->ofType('credit')->opening(-900_000, null)->create(['name' => 'Travel card']);
        $this->rule($user, $card, ['description' => 'Card subscription', 'amount_cents' => 2_000]);

        $forecast = (new ForecastService)->project(LedgerContext::personal($user));

        $this->assertSame(500_000, $forecast['currencies']['USD']['start_cents'], 'only cash accounts make the cash balance');
        $this->assertSame(500_000, $forecast['currencies']['USD']['end_cents'], 'a rule on a credit account does not move cash');
        $this->assertSame('Travel card', $forecast['not_cash']['USD'][0]['name']);
        $this->assertSame('Card subscription', $forecast['excluded'][0]['description']);
        $this->assertStringContainsString('not cash', $forecast['excluded'][0]['reason']);

        $this->actingAs($user)->get(route('forecast.index'))->assertOk()->assertSee('Not counted as cash')->assertSee('Travel card')->assertSee('a credit account (money owed)')->assertDontSee('resume it');
    }

    public function test_a_currency_with_only_a_credit_account_has_no_cash_balance_to_project(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->currency('USD')->ofType('credit')->opening(-300_000, null)->create();

        $forecast = (new ForecastService)->project(LedgerContext::personal($user));

        $this->assertSame([], $forecast['currencies']);
        $this->assertCount(1, $forecast['not_cash']['USD']);
    }

    public function test_an_overdrawn_flat_line_is_neutral_not_red_and_the_biggest_cash_currency_leads(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Account::factory()->for($user)->currency('USD')->opening(-300_000, null)->create(['name' => 'Supplier USD']);
        Account::factory()->for($user)->currency('ZAR')->opening(750_000_00, null)->create(['name' => 'Main account']);

        $forecast = (new ForecastService)->project(LedgerContext::personal($user));
        $this->assertSame(['ZAR', 'USD'], array_keys($forecast['currencies']));
        $this->assertTrue($forecast['currencies']['USD']['overdrawn']);

        $page = $this->actingAs($user)->get(route('forecast.index'))->assertOk();
        $page->assertSee('overdrawn today')->assertSee('no lower than today')->assertDontSee('is-variance', false);
    }

    public function test_a_balance_that_falls_below_zero_is_still_red(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(5_000, null)->create();
        $this->rule($user, $account, ['description' => 'Big bill', 'amount_cents' => 20_000, 'next_occurrence_date' => '2026-10-15', 'start_date' => '2026-10-15']);

        $this->actingAs($user)->get(route('forecast.index'))->assertOk()->assertSee('is-variance', false);
    }

    public function test_it_reports_the_lowest_projected_balance_and_when(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->opening(15_000, null)->create();
        $this->rule($user, $account, ['amount_cents' => 10_000, 'start_date' => '2026-09-10', 'next_occurrence_date' => '2026-10-10']);
        $this->rule($user, $account, ['amount_cents' => 1_000, 'type' => 'income', 'start_date' => '2026-09-20', 'next_occurrence_date' => '2026-10-20']);

        // 150.00 -> Oct 10: 50.00 -> Oct 20: 60.00 -> Nov 10: -40.00 -> Nov 20: -30.00 -> Dec 10: -130.00 -> Dec 20: -120.00
        $zar = (new ForecastService)->project(LedgerContext::personal($user))['currencies']['ZAR'];

        $this->assertSame(-13_000, $zar['lowest_cents']);
        $this->assertSame('2026-12-10', $zar['lowest_date']);
        $this->assertSame(-12_000, $zar['end_cents']);
    }

    public function test_it_only_reads_the_given_users_items(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->rule($other, Account::factory()->for($other)->create(), ['amount_cents' => 99_900]);

        $this->assertSame([], (new ForecastService)->project(LedgerContext::personal($user))['currencies']);
    }

    public function test_the_page_is_labelled_as_a_projection_and_kept_apart_from_actuals(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(250_000, null)->create(['name' => 'Forecast page account']);
        $this->rule($user, $account, ['description' => 'Forecast rent item', 'amount_cents' => 100_000]);
        $this->rule($user, $account, ['description' => 'Forecast paused item', 'paused_at' => now()]);

        $this->actingAs($user)->get(route('forecast.index'))->assertOk()
            ->assertSee('Projection from recurring items, not a prediction')
            ->assertSee('Balance today (actual ledger)')
            ->assertSee('Projected balance in 90 days')
            ->assertSee('ZAR 2,500.00')
            ->assertSee('Forecast rent item')
            ->assertSee('Forecast paused item')
            ->assertSee('Left out')
            ->assertSee('does not include')
            ->assertDontSee('AI-powered')
            ->assertDontSee('AI forecast');
    }

    public function test_the_dashboard_does_not_mix_projected_figures_into_actuals(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(250_000, null)->create();
        $this->rule($user, $account, ['amount_cents' => 77_777_77]);

        // R-44 (deliberate change, D-401): the command centre has a "Coming up" panel that lists the repeating items due in the next
        // 14 days with their own amounts, labelled as scheduled entries. What must never happen is a projected BALANCE reaching the
        // actual position, so the guard is now: the position is the actual ledger balance, and no projected balance or low point is drawn.
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertSee('ZAR 2,500.00')
            ->assertDontSee('Projected balance')
            ->assertDontSee('Projected low point')
            ->assertDontSee('ZAR 75,277.77');
    }

    public function test_the_page_has_an_empty_state_when_nothing_repeats(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Account::factory()->for($user)->opening(250_000, null)->create();

        $this->actingAs($user)->get(route('forecast.index'))->assertOk()
            ->assertSee('Nothing recurring to project')
            ->assertSee('Projection from recurring items, not a prediction');
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('forecast.index'))->assertRedirect(route('login'));
    }
}
