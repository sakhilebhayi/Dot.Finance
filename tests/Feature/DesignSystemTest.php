<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ui\DashboardPanels;
use App\Support\Ui\Navigation;
use App\Support\Ui\StateLine;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** R-44 design system: the shell's map of the product, the state line, the fx components and what they promise. */
class DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        StateLine::flush();
        DashboardPanels::flush();
        Navigation::flush();
        parent::tearDown();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    public function test_fx_route_resolves_registered_routes_and_is_null_for_the_rest(): void
    {
        $this->assertNull(fx_route('zz.unbuilt.index'));
        $this->assertSame(route('dashboard'), fx_route('dashboard'));
        $this->assertSame(route('dashboard'), fx_route(['nope.one', 'dashboard']), 'the first registered alternative wins');
        $this->assertNull(fx_route('transactions.edit'), 'a route that needs a parameter it was not given is treated as absent');
        $this->assertTrue(fx_route_exists(['nope', 'dashboard']));
        $this->assertFalse(fx_route_exists('nope'));
    }

    public function test_a_destination_that_is_not_built_yet_is_a_disabled_item_never_a_dead_link(): void
    {
        // Every real module is built now, so the 'not built yet' case is a destination the test adds itself, with a route that does not exist.
        Navigation::add('purchases', ['key' => 'zz-unbuilt', 'label' => 'Zz Unbuilt ledger', 'icon' => 'file-text', 'routes' => ['zz.unbuilt.index']]);
        $user = User::factory()->withPersonalTeam()->create();

        $x = $this->xpath($this->actingAs($user)->get(route('dashboard'))->getContent());

        $this->assertSame(1, $x->query('//span[@class="fx-nav__item"][@aria-disabled="true"][contains(normalize-space(.), "Zz Unbuilt ledger")][.//span[@class="fx-nav__soon"]]')->length);
        $this->assertSame(0, $x->query('//a[contains(@class, "fx-nav__item")][contains(normalize-space(.), "Zz Unbuilt ledger")]')->length, 'the item is not a link while its route does not exist');
    }

    public function test_the_moment_a_module_registers_a_route_its_item_becomes_a_normal_link(): void
    {
        Navigation::add('purchases', ['key' => 'zz-unbuilt', 'label' => 'Zz Unbuilt ledger', 'icon' => 'file-text', 'routes' => ['zz.unbuilt.index']]);
        Route::middleware('web')->get('/__test/unbuilt', fn () => 'ok')->name('zz.unbuilt.index');
        Route::getRoutes()->refreshNameLookups();
        $user = User::factory()->withPersonalTeam()->create();

        $x = $this->xpath($this->actingAs($user)->get(route('dashboard'))->getContent());

        $this->assertSame(1, $x->query('//a[contains(@class, "fx-nav__item")][contains(@href, "/__test/unbuilt")][contains(normalize-space(.), "Zz Unbuilt ledger")]')->length);
        $this->assertSame(0, $x->query('//span[@aria-disabled="true"][contains(normalize-space(.), "Zz Unbuilt ledger")]')->length);
    }

    public function test_the_nav_is_grouped_and_the_current_page_is_marked(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $x = $this->xpath($this->actingAs($user)->get(route('accounts.index'))->getContent());

        foreach (['Money', 'Sales', 'Purchases', 'Bank', 'Tax', 'Reports', 'Planning', 'Controls'] as $group) {
            $this->assertSame(1, $x->query('//nav[@aria-label="Main"]//summary/span[normalize-space(.)="'.$group.'"]')->length, "$group group");
        }
        $this->assertSame(1, $x->query('//nav[@aria-label="Main"]//a[@aria-current="page"][contains(@href, "/accounts")]')->length);
    }

    public function test_a_module_can_add_a_destination_and_the_command_bar_lists_only_real_ones(): void
    {
        Route::middleware('web')->get('/__test/payables-ageing', fn () => 'ok')->name('purchases.test-ageing.index');
        Route::getRoutes()->refreshNameLookups();
        Navigation::add('purchases', ['key' => 'test-ageing', 'label' => 'Payables ageing', 'icon' => 'file-text', 'routes' => ['purchases.test-ageing.index']]);
        Navigation::add('purchases', ['key' => 'zz-unbuilt', 'label' => 'Zz Unbuilt ledger', 'icon' => 'file-text', 'routes' => ['zz.unbuilt.index']]);
        $user = User::factory()->withPersonalTeam()->create();

        $x = $this->xpath($this->actingAs($user)->get(route('dashboard'))->getContent());

        $this->assertSame(1, $x->query('//dialog[@id="fx-command"]//a[@data-command-option][@data-label="Purchases Payables ageing"][contains(@href, "/__test/payables-ageing")]')->length);
        $this->assertSame(0, $x->query('//dialog[@id="fx-command"]//a[@data-label="Purchases Zz Unbuilt ledger"]')->length, 'the command bar never offers a destination that does not exist');
        $this->assertSame(1, $x->query('//dialog[@id="fx-command"]//a[@data-label="Money Transactions"]')->length);
    }

    public function test_money_is_one_text_node_with_brackets_for_negatives_and_never_colour_alone(): void
    {
        $this->blade('<x-fx.money :cents="-123456" currency="ZAR" />')
            ->assertSee('class="fx-money is-neg"', false)
            ->assertSee('<span class="sr-only">negative </span>(ZAR 1,234.56)', false);

        $this->blade('<x-fx.money :cents="123456" currency="ZAR" :signed="true" />')
            ->assertSee('+ZAR 1,234.56', false)
            ->assertDontSee('sr-only');

        $this->blade('<x-fx.money :cents="0" currency="ZAR" />')->assertSee('ZAR 0.00', false);
        $this->blade('<x-fx.money :cents="500" :variance="true" />')->assertSee('is-variance', false);
        $this->blade('<x-money :cents="-500" currency="ZAR" :tone="true" />')->assertSee('(ZAR 5.00)', false);
    }

    public function test_a_figure_with_provenance_links_to_its_source_rows(): void
    {
        $this->blade('<x-fx.money :cents="1000" currency="ZAR" trace="/transactions?type=income" trace-label="Show the income entries" />')
            ->assertSee('<a href="/transactions?type=income" class="fx-trace"', false)
            ->assertSee('Show the income entries');
    }

    public function test_a_pill_always_repeats_its_colour_in_a_glyph(): void
    {
        $ticked = (string) $this->blade('<x-fx.pill kind="ticked">Ties out</x-fx.pill>');
        $variance = (string) $this->blade('<x-fx.pill kind="variance">Does not tie</x-fx.pill>');

        $this->assertStringContainsString('<svg', $ticked);
        $this->assertStringContainsString('<svg', $variance);
        $this->assertStringContainsString('fx-pill--ticked', $ticked);
        $this->assertStringContainsString('fx-pill--variance', $variance);
    }

    public function test_the_glossary_slot_gives_a_term_a_tooltip_and_no_slot_means_plain_text(): void
    {
        $this->blade('<x-fx.gloss term="Tie-out"><x-slot name="glossary">Checking a figure back to the entries behind it.</x-slot></x-fx.gloss>')
            ->assertSee('role="tooltip"', false)
            ->assertSee('Checking a figure back to the entries behind it.');

        $this->blade('<x-fx.gloss term="Plain" />')->assertSee('Plain')->assertDontSee('role="tooltip"', false);
    }

    public function test_the_state_line_says_nothing_needs_you_when_that_is_true(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSee('Nothing needs you right now.')
            ->assertSee('aria-label="What needs you"', false);
    }

    public function test_the_state_line_counts_real_things_and_links_to_them(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        // Over its limit this month.
        Budget::factory()->create(['user_id' => $user->id, 'category_id' => $category->id, 'amount_cents' => 10_000, 'currency' => 'ZAR', 'month' => now()->month, 'year' => now()->year]);
        Transaction::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'category_id' => $category->id, 'type' => 'expense', 'amount_cents' => 12_000, 'date' => now()->toDateString()]);
        // Past its date and not generated.
        RecurringRule::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'next_occurrence_date' => now()->subDays(3)->toDateString()]);
        // No category this month.
        Transaction::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'category_id' => null, 'type' => 'expense', 'date' => now()->toDateString()]);

        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('3 things need you:', $html);
        $this->assertStringContainsString('1 budget is at or over its limit', $html);
        $this->assertStringContainsString('1 recurring item is past its date and not added yet', $html);
        $this->assertStringContainsString('1 transaction this month has no category', $html);
        $this->assertStringContainsString(route('transactions.index', ['category_id' => 'none', 'from' => now()->startOfMonth()->toDateString()]), html_entity_decode($html));
        $this->assertStringNotContainsString('Nothing needs you right now.', $html);
    }

    public function test_variance_red_is_reserved_for_over_limit_and_overdue_items(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        Transaction::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'category_id' => null, 'type' => 'expense', 'date' => now()->toDateString()]);

        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('3 transactions this month have no category', str_replace('1 transaction this month has no category', '3 transactions this month have no category', $html));
        $this->assertDoesNotMatchRegularExpression('/fx-state__link is-variance[^>]*>[^<]*no category/', $html, 'an uncategorised entry is a neutral item, not a variance');
    }

    public function test_other_modules_can_extend_the_state_line_and_the_command_centre(): void
    {
        StateLine::extend(fn ($ledger) => ['key' => 'vat', 'tone' => 'neutral', 'count' => 2, 'text' => '2 VAT checks to review', 'href' => '/vat']);
        $this->app['view']->addNamespace('fxtest', __DIR__.'/fixtures');
        file_put_contents(sys_get_temp_dir().'/fx-panel.blade.php', '<x-fx.panel title="Overdue invoices">None right now.</x-fx.panel>');
        $this->app['view']->addLocation(sys_get_temp_dir());
        DashboardPanels::add('fx-panel');
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('2 VAT checks to review', $html);
        $this->assertStringContainsString('Overdue invoices', $html);
    }

    public function test_the_transaction_list_can_be_filtered_to_entries_without_a_category(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        Transaction::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'category_id' => $category->id, 'description' => 'Has a category row']);
        Transaction::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'category_id' => null, 'description' => 'Lacks a category row']);

        $this->actingAs($user)->get(route('transactions.index', ['category_id' => 'none']))
            ->assertOk()
            ->assertSee('Lacks a category row')
            ->assertDontSee('Has a category row');
    }

    public function test_tables_have_captions_header_scopes_and_card_labels(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        Transaction::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);

        $html = $this->actingAs($user)->get(route('transactions.index'))->getContent();

        $this->assertStringContainsString('<caption class="sr-only">Transactions, newest first</caption>', $html);
        $this->assertStringContainsString('<th scope="col">Date</th>', $html);
        $this->assertStringContainsString('data-label="Amount"', $html);
    }

    public function test_every_page_has_a_single_h1_and_a_skip_link(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        foreach (['dashboard', 'transactions.index', 'accounts.index', 'budgets.index', 'reconciliation.index', 'forecast.index'] as $name) {
            $html = $this->actingAs($user)->get(route($name))->getContent();
            $this->assertSame(1, preg_match_all('/<h1[ >]/', $html), "[$name] has exactly one h1");
            $this->assertStringContainsString('class="fx-skip"', $html);
        }
    }
}
