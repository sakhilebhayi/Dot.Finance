<?php

namespace Tests\Feature\Reporting;

use App\Models\Reporting\ReportPreset;
use App\Services\Accounting\PostingLine;
use App\Services\Reporting\ReportCache;
use App\Services\Reporting\ReportRegistry;
use App\Services\Reporting\ReportRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;

class ReportPagesTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private function as_(string $role = 'owner'): static
    {
        return $this->inCompany($role === 'owner' ? $this->owner : $this->memberOf($this->company, $role), $this->company);
    }

    public function test_every_role_can_read_every_report(): void
    {
        $this->buildCompanyBooks();
        $keys = app(ReportRegistry::class)->keys();
        $this->assertCount(13, $keys);

        foreach (['owner', 'admin', 'editor', 'viewer'] as $role) {
            $this->as_($role)->get(route('reports.index'))->assertOk()->assertSee('Profit and loss')->assertSee('Balance sheet');
            foreach ($keys as $key) {
                $this->as_($role)->get(route('reports.'.$key, ['from' => '2026-01-01', 'to' => '2026-06-30']))->assertOk();
            }
        }
    }

    public function test_a_report_page_states_its_header_checks_and_assumptions(): void
    {
        $this->buildCompanyBooks();
        $this->as_()->get(route('reports.profit-loss', ['from' => '2026-01-01', 'to' => '2026-06-30', 'compare' => 'last_year']))
            ->assertOk()
            ->assertSee('Acme (Pty) Ltd')->assertSee('1 Jan 2026 to 30 Jun 2026')->assertSee('Compared with')->assertSee('Generated')
            ->assertSee('Ties to the trial balance')->assertSee('Accrual basis')->assertSee('Posting rules v1')
            ->assertSee('Net profit');

        $this->as_()->get(route('reports.balance-sheet', ['to' => '2026-06-30']))->assertOk()->assertSee('As at 30 Jun 2026')->assertSee('Assets equal liabilities plus equity');
        $print = $this->as_()->get(route('reports.profit-loss', ['preset' => 'this_year', 'print' => 1]));
        $print->assertOk()->assertSee('print view')->assertSee('Save as PDF');
    }

    public function test_bad_input_is_refused_with_a_message_not_a_server_error(): void
    {
        $this->buildCompanyBooks();
        $this->as_()->get(route('reports.profit-loss', ['from' => '2026-07-01', 'to' => '2026-06-01']))->assertRedirect(route('reports.profit-loss'))->assertSessionHasErrors('period');
        $this->as_()->get(route('reports.profit-loss', ['from' => 'abc', 'to' => '2026-06-01']))->assertSessionHasErrors('from');
        $this->as_()->get(route('reports.profit-loss', ['basis' => 'barter']))->assertSessionHasErrors('basis');
        $this->as_()->get(route('reports.profit-loss', ['compare' => 'sideways']))->assertSessionHasErrors('compare');
        $this->as_()->get(route('reports.general-ledger', ['account' => 999999, 'preset' => 'this_year']))->assertNotFound();
        $this->as_()->get(route('reports.budget-vs-actual', ['budget' => 999999]))->assertNotFound();
    }

    public function test_without_accounting_the_reports_say_so_and_show_nothing(): void
    {
        $this->buildCompanyBooks(enable: false);
        foreach (['reports.index', 'reports.profit-loss', 'reports.balance-sheet'] as $name) {
            $this->as_()->get(route($name))->assertOk()->assertSee('not switched on')->assertSee('Set up accounting');
        }
        $this->as_('viewer')->get(route('reports.profit-loss'))->assertOk()->assertDontSee('Set up accounting');
    }

    public function test_saved_views_are_created_applied_and_removed_by_the_right_people(): void
    {
        $this->buildCompanyBooks();
        $this->as_()->post(route('reports.presets.store'), ['report' => 'profit-loss', 'name' => 'Quarter on quarter', 'preset' => 'last_quarter', 'compare' => 'previous', 'basis' => 'cash'])
            ->assertRedirect()->assertSessionHas('status');
        $preset = ReportPreset::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['preset' => 'last_quarter', 'compare' => 'previous', 'basis' => 'cash'], collect($preset->params)->only(['preset', 'compare', 'basis'])->all());
        $this->assertArrayNotHasKey('from', $preset->params, 'a named period stays relative so it means the same thing next quarter');

        // Saving the same name again replaces it.
        $this->as_()->post(route('reports.presets.store'), ['report' => 'profit-loss', 'name' => 'Quarter on quarter', 'preset' => 'this_month']);
        $this->assertSame(1, ReportPreset::withoutGlobalScopes()->count());

        $this->as_()->get(route('reports.profit-loss'))->assertSee('Quarter on quarter');
        $this->as_()->post(route('reports.presets.store'), ['report' => 'nope', 'name' => 'x'])->assertSessionHasErrors('report');
        $this->as_()->post(route('reports.presets.store'), ['report' => 'vat', 'name' => ''])->assertSessionHasErrors('name');

        // A viewer cannot remove someone else's view; the owner can.
        $this->as_('viewer')->delete(route('reports.presets.destroy', $preset))->assertForbidden();
        $this->as_()->delete(route('reports.presets.destroy', $preset))->assertRedirect();
        $this->assertSame(0, ReportPreset::withoutGlobalScopes()->count());
    }

    public function test_figures_never_leak_between_ledgers_through_the_pages_or_the_cache(): void
    {
        $this->buildCompanyBooks();
        $other = $this->user();
        $company2 = $this->company($other, 'Other Co');
        $ctx2 = $this->companyContext($company2);
        $acct = $this->companyAccount($company2, ['opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);
        $cat = $this->companyCategory($company2, ['name' => 'Widgets', 'type' => 'income']);
        $this->tx($acct, $cat, 'income', 77_700, '2026-02-02');
        $this->enableAccounting($ctx2, 'ZAR');

        $runner = app(ReportRunner::class);
        $a = $runner->run($this->ctx, 'profit-loss', ['from' => '2026-01-01', 'to' => '2026-06-30']);
        $b = $runner->run($ctx2, 'profit-loss', ['from' => '2026-01-01', 'to' => '2026-06-30']);
        $this->assertSame(75_025_000, $a->cell('Total income', 'total'));
        $this->assertSame(77_700, $b->cell('Total income', 'total'));
        $this->assertNull($a->row('4100 Widgets'));

        $this->as_()->get(route('reports.revenue', ['from' => '2026-01-01', 'to' => '2026-06-30']))->assertDontSee('Widgets');
    }

    public function test_the_cache_serves_repeat_requests_without_reading_the_journals_and_never_serves_stale_books(): void
    {
        $this->buildCompanyBooks();
        $runner = app(ReportRunner::class);
        $input = ['from' => '2026-01-01', 'to' => '2026-06-30'];

        $first = $runner->run($this->ctx, 'profit-loss', $input);

        DB::enableQueryLog();
        $second = $runner->run($this->ctx, 'profit-loss', $input);
        $linesQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'journal_lines') && str_contains(strtolower($q['query']), 'group by "l"."gl_account_id"'))->count();
        DB::disableQueryLog();
        $this->assertSame(0, $linesQueries, 'a repeat reads no per-account journal aggregates (the independent tie-out check is still computed live, on purpose)');
        $this->assertSame($first->rows, $second->rows);

        // A posting changes the fingerprint, so the next figure is the new one.
        $before = app(ReportCache::class)->fingerprint($this->ctx);
        $this->journal('2026-06-10', 'Late sale', [
            new PostingLine('bank', debitCents: 5_000), new PostingLine('sales', creditCents: 5_000),
        ]);
        $this->assertNotSame($before, app(ReportCache::class)->fingerprint($this->ctx));
        $third = $runner->run($this->ctx, 'profit-loss', $input);
        $this->assertSame(75_030_000, $third->cell('Total income', 'total'));
        $this->assertSame(53_580_000, $third->cell('Net profit', 'total'));

        // Re-classifying an account changes what a report groups, so that also invalidates.
        $sales = $this->glCode('4000');
        $fp = app(ReportCache::class)->fingerprint($this->ctx);
        $this->travel(2)->seconds();
        $sales->forceFill(['class' => 'expense', 'normal_side' => 'debit'])->save();
        $this->assertNotSame($fp, app(ReportCache::class)->fingerprint($this->ctx));
    }

    public function test_dates_default_to_the_financial_year_and_the_form_remembers_what_was_asked(): void
    {
        $this->buildCompanyBooks();
        $page = $this->as_()->get(route('reports.profit-loss', ['preset' => 'last_month', 'basis' => 'cash', 'compare' => 'previous']));
        $page->assertOk()->assertSee('Last month')->assertSee('Cash basis');
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/<option value="cash"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="previous"\s+selected/', $html);
        $this->assertSame(CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth()->toDateString(), CarbonImmutable::parse(app(ReportRunner::class)->request($this->ctx, 'profit-loss', ['preset' => 'last_month'])->period->from)->toDateString());
    }
}
