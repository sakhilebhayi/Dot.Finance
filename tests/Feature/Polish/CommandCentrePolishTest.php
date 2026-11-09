<?php

namespace Tests\Feature\Polish;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerSummary;
use App\Support\Ledger\LedgerContext;
use App\Support\Ui\Navigation;
use App\Support\Ui\StartHere;
use App\Support\Ui\StateLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAccounting;
use Tests\TestCase;

/** R-44 polish: the Command centre's one hero figure per currency, the one bracket rule, budgets that show their overrun, the sidebar. */
class CommandCentrePolishTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        StateLine::flush();
        Navigation::flush();
        parent::tearDown();
    }

    private function ledgerWithACardAndADollarAccount(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        Account::factory()->create(['user_id' => $user->id, 'name' => 'Everyday', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 1_000_000]);
        Account::factory()->create(['user_id' => $user->id, 'name' => 'Savings', 'type' => 'savings', 'currency' => 'ZAR', 'opening_balance_cents' => 500_000]);
        Account::factory()->create(['user_id' => $user->id, 'name' => 'Visa', 'type' => 'credit', 'currency' => 'ZAR', 'opening_balance_cents' => -300_000]);
        Account::factory()->create(['user_id' => $user->id, 'name' => 'Supplier USD', 'type' => 'checking', 'currency' => 'USD', 'opening_balance_cents' => -300_000]);

        return $user;
    }

    // ── jury defect 2: one hero figure per currency, with the other figures labelled by what they leave out ──

    public function test_the_hero_is_the_cash_in_cash_accounts_and_a_card_is_beside_it_not_netted_into_it(): void
    {
        $user = $this->ledgerWithACardAndADollarAccount();
        $zar = app(LedgerSummary::class)->position(LedgerContext::personal($user))['ZAR'];

        $this->assertSame(1_500_000, $zar['cash_cents'], 'checking and savings only');
        $this->assertSame(300_000, $zar['owed_cents'], 'the card is what is owed, a positive amount, kept apart');
        $this->assertSame(1_200_000, $zar['balance_cents'], 'the old all-accounts total is unchanged for callers that want it');
        $this->assertSame('Visa', $zar['bridge'][0]['name']);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('Cash available', $html);
        $this->assertStringContainsString('ZAR 15,000.00', $html, 'the hero');
        $this->assertStringContainsString('Not in the cash figure above', $html);
        $this->assertStringContainsString('owed <span', $html);
        $this->assertStringContainsString('Cash after settling the cards:', $html, 'the other figure has its own label and the sum that makes it');
        $this->assertStringContainsString('ZAR 12,000.00', $html);
    }

    public function test_the_main_currency_leads_and_a_small_negative_dollar_account_is_never_the_headline(): void
    {
        $user = $this->ledgerWithACardAndADollarAccount();
        $position = app(LedgerSummary::class)->position(LedgerContext::personal($user));

        $this->assertSame(['USD', 'ZAR'], array_keys($position), 'alphabetical order would put USD first');
        $this->assertSame(['ZAR', 'USD'], LedgerSummary::currencyOrder($position), 'ZAR has more cash accounts, so it leads');
        $this->assertSame(['USD', 'ZAR'], LedgerSummary::currencyOrder($position, 'USD'), 'the ledger base currency, when the books name one, leads');

        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();
        $this->assertLessThan(strpos($html, 'USD 3,000.00'), strpos($html, 'ZAR 15,000.00'), 'ZAR is drawn before the negative USD account');
    }

    public function test_account_kinds(): void
    {
        $this->assertSame('cash', LedgerSummary::kindOf('checking'));
        $this->assertSame('cash', LedgerSummary::kindOf('savings'));
        $this->assertSame('cash', LedgerSummary::kindOf('cash'));
        $this->assertSame('owed', LedgerSummary::kindOf('credit'));
        $this->assertSame('invested', LedgerSummary::kindOf('investment'));
    }

    // ── jury defect 5: a budget says how far over it is ──

    public function test_the_budgets_page_and_the_command_centre_say_the_overspend_in_money_and_percent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 5_000_000]);
        $fuel = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Fuel']);
        Budget::factory()->create(['user_id' => $user->id, 'category_id' => $fuel->id, 'amount_cents' => 400_000, 'currency' => 'ZAR', 'month' => now()->month, 'year' => now()->year]);
        Transaction::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'category_id' => $fuel->id, 'type' => 'expense', 'amount_cents' => 1_273_000, 'date' => now()->toDateString()]);

        foreach (['budgets.index', 'dashboard'] as $route) {
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString('ZAR 8,730.00 over the limit (218% more than planned)', $html, $route);
            $this->assertStringNotContainsString('100% used', $html, $route);
            $this->assertStringContainsString('fx-meter__mark', $html, $route.': the bar marks the limit and draws the overrun');
        }
    }

    // ── jury defect 6: one bracket convention ──

    public function test_brackets_mean_a_negative_amount_and_a_change_is_never_bracketed(): void
    {
        $this->blade('<x-fx.money :cents="-11833500" currency="ZAR" />')->assertSee('(ZAR 118,335.00)', false);
        $this->blade('<x-fx.money :cents="11833500" currency="ZAR" />')->assertDontSee('(', false);

        // A fall in spending is a change, not a negative amount: it reads "Down", with no bracket and no minus sign on the amount.
        $down = (string) $this->blade('<x-fx.change :cents="-11808000" currency="ZAR" :pct="-100" />');
        $this->assertStringContainsString('Down', $down);
        $this->assertStringContainsString('ZAR 118,080.00', $down);
        $this->assertStringNotContainsString('(', $down);
        $this->assertStringContainsString('-100%', $down);

        $up = (string) $this->blade('<x-fx.change :cents="500" currency="ZAR" :pct="12" />');
        $this->assertStringContainsString('Up', $up);
        $this->assertStringContainsString('+12%', $up);
        $this->assertStringContainsString('No change', (string) $this->blade('<x-fx.change :cents="0" currency="ZAR" />'));
    }

    public function test_a_customer_who_holds_a_credit_is_never_shown_as_owing_a_negative_amount(): void
    {
        $credit = (string) $this->blade('<x-fx.owed :cents="-52200" currency="ZAR" />');
        $this->assertStringContainsString('Credit on account', $credit);
        $this->assertStringContainsString('ZAR 522.00', $credit);
        $this->assertStringNotContainsString('(', $credit, 'no bracket, so it cannot read as owes you (522.00)');
        $this->assertStringNotContainsString('negative', $credit);

        $owes = (string) $this->blade('<x-fx.owed :cents="52200" currency="ZAR" />');
        $this->assertStringContainsString('ZAR 522.00', $owes);
        $this->assertStringNotContainsString('Credit', $owes);

        $this->assertStringContainsString('Credit with supplier', (string) $this->blade('<x-fx.owed :cents="-100" currency="ZAR" side="payable" />'));
    }

    // ── jury defect 8: the strip ──

    public function test_the_strip_is_one_slim_line_that_opens_the_list_and_does_not_repeat_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        StateLine::extend(fn ($l) => [
            ['key' => 'a', 'tone' => 'variance', 'count' => 1, 'text' => 'Alpha item is overdue', 'href' => '/a'],
            ['key' => 'b', 'tone' => 'neutral', 'count' => 1, 'text' => 'Beta item is waiting', 'href' => '/b'],
        ]);

        foreach (['dashboard', 'accounts.index', 'budgets.index'] as $route) {
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'class="fx-state"'), $route.': drawn once');
            $this->assertStringContainsString('2 things need you', $html);
            $this->assertStringContainsString(', 1 urgent', $html);
            $this->assertStringContainsString(route('dashboard').'#needs-you', $html, 'it opens the list');
            if ($route !== 'dashboard') {
                $this->assertStringNotContainsString('Alpha item is overdue', $html, $route.': the strip does not print the items');
            }
        }

        $dashboard = $this->actingAs($user)->get(route('dashboard'))->getContent();
        $this->assertSame(1, substr_count($dashboard, 'Alpha item is overdue'), 'the list exists once on the dashboard: the Needs you panel');
    }

    // ── the sidebar ──

    public function test_only_the_group_holding_the_current_page_is_open(): void
    {
        $groups = Navigation::resolved('accounts.index');
        $open = collect($groups)->where('open', true)->pluck('key')->all();

        $this->assertSame(['money'], $open, 'accounts live in Money; every other group is collapsed');
        $this->assertGreaterThan(1, count($groups));

        $reports = collect(Navigation::resolved('reports.profit-loss'))->where('open', true)->pluck('key')->all();
        $this->assertContains('reports', $reports);
        $this->assertNotContains('money', $reports);

        $none = collect(Navigation::resolved('profile.show'))->where('open', true)->pluck('key')->all();
        $this->assertSame(['money'], $none, 'a page in no group still leaves the first group open');
    }

    public function test_destinations_that_only_read_the_books_are_left_out_while_accounting_is_off(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $keys = fn () => collect(Navigation::resolved('dashboard'))->flatMap(fn ($g) => collect($g['items'])->pluck('key'))->all();

        $off = $keys();
        foreach (['profit-loss', 'balance-sheet', 'trial-balance', 'general-ledger', 'chart', 'journals', 'periods'] as $gone) {
            $this->assertNotContains($gone, $off, $gone.' needs the books');
        }
        $this->assertContains('accounting-setup', $off, 'the way to switch accounting on stays');
        $this->assertContains('cash-flow', $off, 'a report that needs no books stays');

        $this->enableAccounting(LedgerContext::personal($user));
        $on = $keys();
        foreach (['profit-loss', 'balance-sheet', 'trial-balance', 'chart', 'journals'] as $back) {
            $this->assertContains($back, $on, $back.' returns once accounting is on');
        }
    }

    public function test_a_new_ledger_sees_a_short_start_here_list_that_goes_away_once_it_has_an_account_and_an_entry(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('Start here', $html);
        $this->assertStringContainsString('Add an account', $html);

        $this->assertSame(['account', 'entry', 'invoice', 'bill'], array_column(StartHere::for(), 'key'));
        $this->assertSame([false, false], array_slice(array_column(StartHere::for(), 'done'), 0, 2));

        $account = Account::factory()->create(['user_id' => $user->id]);
        $this->assertTrue(StartHere::for()[0]['done'], 'the first step ticks itself');

        Transaction::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'type' => 'income']);
        $this->assertSame([], StartHere::for());
        $this->assertStringNotContainsString('Start here', $this->actingAs($user)->get(route('dashboard'))->getContent());
    }
}
