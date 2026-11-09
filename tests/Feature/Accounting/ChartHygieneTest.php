<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Category;
use App\Models\Intelligence\FinanceInsight;
use App\Models\Shared\Party;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Intelligence\InsightEngine;
use App\Services\Shared\PartyDuplicates;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/**
 * Jury #17 and #27: duplicate and odd accounts, a Suspense balance sitting in the assets, and a customer entered twice. The chart
 * refuses a second account with a name or code already used; a category never makes a second "Rent"; Suspense with a balance is
 * flagged on the chart and under "What needs attention"; a customer who looks like an existing one is flagged there and on create.
 */
class ChartHygieneTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private function suspense(): GlAccount
    {
        return LedgerContext::run($this->ledger, fn () => GlAccount::query()->where('system_key', 'suspense')->firstOrFail());
    }

    private function parkInSuspense(int $cents): void
    {
        LedgerContext::run($this->ledger, fn () => app(JournalPoster::class)->post(new PostingRequest(
            CarbonImmutable::today(), 'manual', 'park-1', 1, 'Unsorted deposit', [new PostingLine('bank', debitCents: $cents), new PostingLine('suspense', creditCents: $cents)],
        )));
    }

    public function test_a_second_account_with_the_same_name_is_refused_whatever_the_capitals(): void
    {
        $this->setUpSales();
        $existing = LedgerContext::run($this->ledger, fn () => GlAccount::query()->where('name', 'Rent')->firstOrFail());

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage("already an account called Rent ({$existing->code})");
        LedgerContext::run($this->ledger, fn () => app(Chart::class)->create($this->ledger, ['code' => '6999', 'name' => '  rent ', 'class' => 'expense']));
    }

    public function test_the_add_account_form_says_so_in_plain_words_and_adds_nothing(): void
    {
        $this->setUpSales();
        $before = LedgerContext::run($this->ledger, fn () => GlAccount::query()->count());

        $this->actingAs($this->owner)->post(route('accounting.chart.store'), ['code' => '6998', 'name' => 'Rent', 'class' => 'expense'])->assertSessionHasErrors('name');
        $this->post(route('accounting.chart.store'), ['code' => '1990', 'name' => 'Brand new thing', 'class' => 'asset'])->assertSessionHasErrors('code');

        $this->assertSame($before, LedgerContext::run($this->ledger, fn () => GlAccount::query()->count()));

        $this->post(route('accounting.chart.store'), ['code' => '6998', 'name' => 'Sponsorships', 'class' => 'expense'])->assertSessionHasNoErrors()->assertRedirect(route('accounting.chart.index'));
    }

    public function test_renaming_an_account_to_a_name_in_use_is_refused(): void
    {
        $this->setUpSales();
        $insurance = LedgerContext::run($this->ledger, fn () => GlAccount::query()->where('name', 'Insurance')->firstOrFail());

        $this->actingAs($this->owner)->put(route('accounting.chart.update', $insurance), ['code' => $insurance->code, 'name' => 'Rent'])->assertSessionHasErrors('name');
        $this->assertSame('Insurance', $insurance->refresh()->name);
    }

    public function test_a_category_takes_over_the_account_of_the_same_name_instead_of_making_a_second(): void
    {
        $this->setUpSales();
        $rent = LedgerContext::run($this->ledger, fn () => GlAccount::query()->where('name', 'Rent')->firstOrFail());
        $before = LedgerContext::run($this->ledger, fn () => GlAccount::query()->where('name', 'Rent')->count());

        $category = LedgerContext::run($this->ledger, fn () => Category::create($this->ledger->ownerAttributes() + ['name' => 'Rent', 'type' => 'expense', 'color' => '#888888', 'icon' => 'home']));
        $mapped = LedgerContext::run($this->ledger, fn () => app(Chart::class)->mapCategory($category));

        $this->assertSame($rent->id, $mapped->id);
        $this->assertSame($before, LedgerContext::run($this->ledger, fn () => GlAccount::query()->where('name', 'Rent')->count()), 'still one Rent');
    }

    public function test_a_balance_in_suspense_is_flagged_on_the_chart_and_under_what_needs_attention(): void
    {
        $this->setUpSales();
        $this->actingAs($this->owner)->get(route('accounting.chart.index'))->assertOk()->assertDontSee('Should be nil');
        LedgerContext::run($this->ledger, fn () => app(InsightEngine::class)->scan($this->ledger));
        $this->assertSame(0, FinanceInsight::query()->forContext($this->ledger)->where('kind', 'suspense_balance')->count(), 'nothing to say while it is nil');

        $this->parkInSuspense(740_000);

        $this->get(route('accounting.chart.index'))->assertOk()->assertSee('Should be nil')->assertSee('7,400.00')->assertSee('Suspense is a parking place');
        LedgerContext::run($this->ledger, fn () => app(InsightEngine::class)->scan($this->ledger));
        $finding = FinanceInsight::query()->forContext($this->ledger)->where('kind', 'suspense_balance')->sole();
        $this->assertSame('warning', $finding->severity);
        $this->assertStringContainsString('7,400.00', $finding->title);
        $this->get(route('intel.attention.index'))->assertOk()->assertSee('Suspense holds');
    }

    public function test_a_customer_entered_twice_is_a_finding_and_clearing_it_resolves_it(): void
    {
        $this->setUpSales();
        LedgerContext::run($this->ledger, fn () => Party::create($this->ledger->ownerAttributes() + ['type' => 'customer', 'name' => 'ACME Retail (Pty) Ltd', 'status' => 'active']));
        LedgerContext::run($this->ledger, fn () => Party::create($this->ledger->ownerAttributes() + ['type' => 'customer', 'name' => 'Different Trader', 'status' => 'active']));

        LedgerContext::run($this->ledger, fn () => app(InsightEngine::class)->scan($this->ledger));
        $finding = FinanceInsight::query()->forContext($this->ledger)->where('kind', 'duplicate_party')->sole();
        $this->assertStringContainsString('look like one', $finding->title);
        $this->assertStringContainsString('Acme Retail', $finding->title);
        $this->get(route('intel.attention.index'))->assertOk()->assertSee('look like one');

        LedgerContext::run($this->ledger, fn () => Party::query()->where('name', 'ACME Retail (Pty) Ltd')->firstOrFail()->update(['status' => 'archived']));
        LedgerContext::run($this->ledger, fn () => app(InsightEngine::class)->scan($this->ledger));
        $this->assertSame('resolved', $finding->refresh()->status);
    }

    public function test_adding_a_customer_who_looks_like_an_existing_one_warns_first_and_can_be_confirmed(): void
    {
        $this->setUpSales();
        $count = fn () => LedgerContext::run($this->ledger, fn () => Party::query()->count());
        $before = $count();

        $this->actingAs($this->owner)->post(route('parties.store'), ['type' => 'customer', 'name' => 'Acme Retail'])
            ->assertRedirect()->assertSessionHas('similar_parties');
        $this->assertSame($before, $count(), 'nothing was added yet');

        $this->from(route('parties.create'))->followingRedirects()->post(route('parties.store'), ['type' => 'customer', 'name' => 'acme retail pty'])->assertSee('This looks like someone already on your list')->assertSee('Add it anyway');

        $this->post(route('parties.store'), ['type' => 'customer', 'name' => 'Acme Retail', 'confirm_duplicate' => '1'])->assertSessionHasNoErrors();
        $this->assertSame($before + 1, $count());
    }

    public function test_a_supplier_of_the_same_name_as_a_customer_is_not_flagged(): void
    {
        $this->setUpSales();

        $this->actingAs($this->owner)->post(route('parties.store'), ['type' => 'supplier', 'name' => 'Acme Retail'])->assertSessionMissing('similar_parties');
    }

    public function test_the_matching_rules(): void
    {
        $same = fn (string $a, string $b) => PartyDuplicates::reason(['name' => $a], ['name' => $b]);

        $this->assertNotNull($same('Gardens Pantry', 'Gardens Pantry (Pty) Ltd'));
        $this->assertNotNull($same('Cape Green Importers', 'Cape Green Coffee Importers'));
        $this->assertNotNull($same('Woodstock Deli', 'Woodstok Deli'));
        $this->assertNull($same('Harbour Hotel Group', 'Southern Suburbs Hotel'));
        $this->assertNull($same('Green Co', 'Green Fields'), 'one shared word is not enough');
        $this->assertSame('the same tax number', PartyDuplicates::reason(['name' => 'A One', 'tax_number' => '4123 456 789'], ['name' => 'Totally Else', 'tax_number' => '4123456789']));
    }
}
