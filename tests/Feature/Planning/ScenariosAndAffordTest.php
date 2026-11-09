<?php

namespace Tests\Feature\Planning;

use App\Models\Accounting\LedgerSetting;
use App\Models\Planning\Dimension;
use App\Models\Planning\Scenario;
use App\Services\Planning\AffordCalculator;
use App\Services\Reporting\ReportRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;

class ScenariosAndAffordTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private const LEDGER_TABLES = ['journal_entries', 'journal_lines', 'accounting_periods', 'gl_accounts', 'ledger_settings', 'accounts', 'categories', 'transactions', 'payments', 'payment_allocations', 'recurring_rules', 'budgets', 'parties'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCompanyBooks();
        $this->travelTo(CarbonImmutable::parse('2026-07-01 10:00:00'));
    }

    private function as_(string $role = 'owner'): static
    {
        return $this->inCompany($role === 'owner' ? $this->owner : $this->memberOf($this->company, $role), $this->company);
    }

    /** A hash of every table that holds the books. A scenario must leave it exactly as it was. */
    private function books(): string
    {
        $out = [];
        foreach (self::LEDGER_TABLES as $t) {
            $out[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return md5(json_encode($out));
    }

    private function form(array $over = []): array
    {
        return $over + ['name' => 'Faster collection', 'horizon' => 'weeks_13', 'assumptions' => [
            ['type' => 'collect_faster', 'days' => '7'],
            ['type' => 'new_cost', 'name' => 'New hire', 'amount' => '25000.00', 'start_on' => '2026-08-01', 'months' => ''],
            ['type' => ''],
        ]];
    }

    public function test_scenarios_are_saved_normalised_and_every_use_of_one_leaves_the_books_untouched(): void
    {
        $this->party('Cust', 'customer', 30);
        $before = $this->books();

        $this->as_()->post(route('planning.scenarios.store'), $this->form())->assertRedirect()->assertSessionHas('status');
        $scenario = Scenario::withoutGlobalScopes()->firstOrFail();
        $this->assertSame([['type' => 'collect_faster', 'days' => 7], ['type' => 'new_cost', 'name' => 'New hire', 'amount_cents' => 2_500_000, 'start_on' => '2026-08-01', 'months' => null]], $scenario->assumptions);

        $page = $this->as_()->get(route('planning.scenarios.show', $scenario));
        $page->assertOk()->assertSee('projected, not guaranteed')->assertSee('writes nothing')->assertSee('Customers pay 7 days sooner than expected.')->assertSee('ZAR 25,000.00 a month from 2026-08-01 with no end.');
        $this->as_()->get(route('planning.cash.index', ['scenario' => $scenario->id, 'horizon' => 'months_12']))->assertOk()->assertSee('Scenario: Faster collection');
        $this->as_()->get(route('planning.afford', ['amount' => '1000', 'on' => '2026-07-10', 'mode' => 'once', 'scenario' => $scenario->id]))->assertOk();
        $this->as_()->put(route('planning.scenarios.update', $scenario), $this->form(['name' => 'Renamed']))->assertRedirect();

        $this->assertSame($before, $this->books(), 'creating, running, comparing and editing scenarios wrote nothing to the books');

        $this->as_()->delete(route('planning.scenarios.destroy', $scenario))->assertRedirect(route('planning.scenarios.index'));
        $this->assertSame(0, Scenario::withoutGlobalScopes()->count());
        $this->assertSame($before, $this->books());
    }

    public function test_validation_names_the_problem_and_names_stay_unique_per_ledger(): void
    {
        $this->as_()->post(route('planning.scenarios.store'), $this->form())->assertRedirect();
        $this->as_()->post(route('planning.scenarios.store'), $this->form())->assertSessionHasErrors('name');
        $this->as_()->post(route('planning.scenarios.store'), $this->form(['name' => 'Bad', 'assumptions' => [['type' => 'collect_faster', 'days' => '400']]]))->assertSessionHasErrors('assumptions');
        $this->as_()->post(route('planning.scenarios.store'), $this->form(['name' => 'Empty', 'assumptions' => [['type' => '']]]))->assertSessionHasErrors('assumptions');
        $this->as_()->post(route('planning.scenarios.store'), $this->form(['name' => 'Odd horizon', 'horizon' => 'forever']))->assertSessionHasErrors('horizon');
        $this->assertSame(1, Scenario::withoutGlobalScopes()->count());
    }

    public function test_viewers_read_scenarios_but_only_budget_managers_change_them(): void
    {
        $this->as_()->post(route('planning.scenarios.store'), $this->form());
        $scenario = Scenario::withoutGlobalScopes()->firstOrFail();

        foreach (['viewer', 'editor', 'admin'] as $role) {
            $this->as_($role)->get(route('planning.scenarios.index'))->assertOk()->assertSee('Faster collection');
            $this->as_($role)->get(route('planning.scenarios.show', $scenario))->assertOk();
        }
        $this->as_('viewer')->get(route('planning.scenarios.create'))->assertForbidden();
        $this->as_('viewer')->post(route('planning.scenarios.store'), $this->form(['name' => 'X']))->assertForbidden();
        $this->as_('viewer')->put(route('planning.scenarios.update', $scenario), $this->form(['name' => 'X']))->assertForbidden();
        $this->as_('viewer')->delete(route('planning.scenarios.destroy', $scenario))->assertForbidden();
        $this->as_('viewer')->get(route('planning.scenarios.index'))->assertDontSee('New scenario');
        $this->as_('editor')->get(route('planning.scenarios.create'))->assertOk();
    }

    public function test_another_ledgers_scenario_is_not_found_anywhere_it_could_be_named(): void
    {
        $this->as_()->post(route('planning.scenarios.store'), $this->form());
        $scenario = Scenario::withoutGlobalScopes()->firstOrFail();
        $other = $this->user();
        $company2 = $this->company($other, 'Other Co');
        $this->enableAccounting($this->companyContext($company2), 'ZAR');
        $as = fn () => $this->inCompany($other, $company2);

        $as()->get(route('planning.scenarios.show', $scenario))->assertNotFound();
        $as()->get(route('planning.scenarios.edit', $scenario))->assertNotFound();
        $as()->put(route('planning.scenarios.update', $scenario), $this->form())->assertNotFound();
        $as()->delete(route('planning.scenarios.destroy', $scenario))->assertNotFound();
        $as()->get(route('planning.cash.index', ['scenario' => $scenario->id]))->assertNotFound();
        $as()->get(route('planning.afford', ['amount' => '10', 'on' => '2026-07-10', 'mode' => 'once', 'scenario' => $scenario->id]))->assertNotFound();
        $as()->get(route('planning.scenarios.index'))->assertDontSee('Faster collection');
    }

    public function test_the_cash_page_keeps_actual_and_projected_apart_in_words_and_marks_a_shortage(): void
    {
        $s = $this->party('Big Supplier', 'supplier', 30);
        $this->bill('2026-06-20', 54_500_000, $s, 'Rent', null, 'big');

        $page = $this->as_()->get(route('planning.cash.index'));
        $page->assertOk()
            ->assertSee('Cash today')->assertSee('actual')
            ->assertSee('projected, not guaranteed')->assertSee('Projected shortage')->assertSee('20 Jul 2026')
            ->assertSee('Today (actual)')->assertSee('(projected)')
            ->assertSee('is not a promise')->assertSee('No tax payments are included');
        $page->assertSee('ZAR 540,750.00', false);

        $buffered = $this->as_()->get(route('planning.cash.index', ['buffer' => '600000.00']));
        $buffered->assertOk()->assertSee('your buffer of ZAR 600,000.00');
        $this->as_()->get(route('planning.cash.index', ['buffer' => '12e3']))->assertSessionHasErrors('buffer');
        $this->as_()->get(route('planning.cash.index', ['horizon' => 'years_5']))->assertSessionHasErrors('horizon');
    }

    public function test_can_i_afford_it_answers_in_projection_language_and_finds_the_date_it_breaks(): void
    {
        $settings = LedgerSetting::query()->forContext($this->ctx)->first();
        $calc = app(AffordCalculator::class);
        $today = CarbonImmutable::parse('2026-07-01');

        $fine = $calc->check($this->ctx, $settings, $today, 1_000_000, $today->addDays(10), 'once', 1, 5_000_000);
        $this->assertTrue($fine['stays_above_buffer']);
        $this->assertSame(54_075_000 - 1_000_000 - 5_000_000, $fine['headroom_cents']);
        $this->assertStringContainsString('projected to stay above', $fine['headline']);
        $this->assertStringContainsString('not a guarantee', $fine['caveat']);

        $broke = $calc->check($this->ctx, $settings, $today, 60_000_000, $today->addDays(10), 'once', 1, 1_000_000);
        $this->assertFalse($broke['stays_above_buffer']);
        $this->assertStringContainsString('projected to fall below', $broke['headline']);
        $this->assertStringContainsString('11 Jul 2026', $broke['headline']);
        $this->assertSame(54_075_000 - 60_000_000, $broke['with']['lowest']['balance_cents']);
        $this->assertSame(54_075_000, $broke['without']['lowest']['balance_cents'], 'without the spend nothing changes');

        // A monthly spend repeats and can use the 12-month horizon.
        $monthly = $calc->check($this->ctx, $settings, $today, 5_000_000, $today->addDays(5), 'monthly', 12, 0);
        $this->assertSame('months_12', $monthly['horizon']);
        $this->assertSame(54_075_000 - 12 * 5_000_000, $monthly['with']['closing_cents']);
    }

    public function test_the_afford_page_validates_and_never_writes(): void
    {
        $before = $this->books();
        $ok = $this->as_()->get(route('planning.afford', ['amount' => '10000.00', 'on' => '2026-07-15', 'mode' => 'once', 'buffer' => '1000.00']));
        $ok->assertOk()->assertSee('Projected to stay above your buffer')->assertSee('This is a projection, not a guarantee')->assertSee('projection');

        $this->as_()->get(route('planning.afford'))->assertOk()->assertDontSee('Projected to');   // the form alone
        $this->as_()->get(route('planning.afford', ['amount' => '0', 'on' => '2026-07-15', 'mode' => 'once']))->assertSessionHasErrors('amount');
        $this->as_()->get(route('planning.afford', ['amount' => '10', 'on' => '2026-06-01', 'mode' => 'once']))->assertSessionHasErrors('on');
        $this->as_()->get(route('planning.afford', ['amount' => '10', 'on' => '2028-06-01', 'mode' => 'once']))->assertSessionHasErrors('on');
        $this->as_()->get(route('planning.afford', ['amount' => '1e3', 'on' => '2026-07-15', 'mode' => 'once']))->assertSessionHasErrors('amount');
        $this->as_()->get(route('planning.afford', ['amount' => '10', 'on' => '2026-07-15', 'mode' => 'weekly']))->assertSessionHasErrors('mode');
        $this->assertSame($before, $this->books());
    }

    public function test_dimensions_are_named_by_budget_managers_and_stay_in_their_ledger(): void
    {
        $this->as_()->post(route('planning.dimensions.store'), ['kind' => 'department', 'code' => '3', 'name' => 'Sales'])->assertRedirect();
        $this->as_()->post(route('planning.dimensions.store'), ['kind' => 'department', 'code' => '3', 'name' => 'Dup'])->assertSessionHasErrors('code');
        $this->as_()->post(route('planning.dimensions.store'), ['kind' => 'department', 'code' => 'bad code!', 'name' => 'X'])->assertSessionHasErrors('code');
        $this->as_('viewer')->post(route('planning.dimensions.store'), ['kind' => 'project', 'code' => '9', 'name' => 'Nope'])->assertForbidden();
        $this->as_('viewer')->get(route('planning.dimensions.index'))->assertOk()->assertSee('department:3')->assertSee('Sales');

        // Named, the reports use the name; unnamed they use the raw reference.
        $this->invoice('2026-06-10', 100_000, $this->party('A'), 'department:3', 1);
        $r = app(ReportRunner::class)->run($this->ctx, 'performance', ['from' => '2026-06-01', 'to' => '2026-06-30']);
        $this->assertNotNull($r->row('Sales'));
        $dim = Dimension::withoutGlobalScopes()->firstOrFail();
        $this->as_()->put(route('planning.dimensions.update', $dim), ['name' => 'Retail', 'is_active' => '0'])->assertRedirect();
        $this->assertSame('Retail', $dim->refresh()->name);
        $this->assertFalse($dim->is_active);
    }
}
