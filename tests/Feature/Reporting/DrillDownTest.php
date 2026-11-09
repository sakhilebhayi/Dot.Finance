<?php

namespace Tests\Feature\Reporting;

use App\Models\Shared\TaxCode;
use App\Services\Accounting\PostingLine;
use App\Services\Planning\PlanBudgets;
use App\Services\Reporting\DrillDown;
use App\Services\Reporting\ReportRegistry;
use App\Services\Reporting\ReportRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;

/**
 * "Every number a drill-down." For every report, every money figure that carries a drill link is checked against the
 * journal lines the drill returns: their total, in the sign the report shows, must equal the figure to the cent.
 */
class DrillDownTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private function books(): void
    {
        $cats = $this->buildCompanyBooks();
        $acme = $this->party('Acme Retail');
        $beta = $this->party('Beta Stores', 'customer', 14);
        $land = $this->party('Landlord', 'supplier');
        $vat = TaxCode::create(['jurisdiction' => 'ZA', 'code' => 'ZA_VAT_STD', 'name' => 'Standard rate', 'kind' => 'standard', 'applies_to' => 'both', 'rate_ref' => 'ZA_VAT', 'box_output' => '1', 'box_input' => '15', 'valid_from' => '2018-04-01']);

        $this->journal('2026-02-10', 'Coded invoice', [
            new PostingLine('ar', debitCents: 1_150_000, partyId: $acme->id),
            new PostingLine('sales', creditCents: 1_000_000, partyId: $acme->id, taxCodeId: $vat->id, dimension: 'project:12'),
            new PostingLine('vat_output', creditCents: 150_000, partyId: $acme->id, taxCodeId: $vat->id),
        ], 'invoice', 5001);
        $this->receipt('2026-03-01', 400_000, $acme, 5001);
        $this->invoice('2026-04-20', 300_000, $beta, 'department:3', 5002, 45_000);
        $this->journal('2026-05-02', 'Coded bill', [
            new PostingLine($this->glCode('6100')->id, debitCents: 200_000, partyId: $land->id, taxCodeId: $vat->id, dimension: 'department:3'),
            new PostingLine('vat_input', debitCents: 30_000, partyId: $land->id, taxCodeId: $vat->id),
            new PostingLine('ap', creditCents: 230_000, partyId: $land->id),
        ], 'bill', 5003);
        $this->payBill('2026-05-30', 100_000, $land, 5003);

        // A plan for the budget report.
        $plan = app(PlanBudgets::class)->create($this->ctx, $this->owner, ['name' => 'Plan 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'currency' => 'ZAR']);
        app(PlanBudgets::class)->setLines($plan, [
            ['gl_account_id' => $this->glCode('4100')->id, 'annual' => 120_000_000],
            ['gl_account_id' => $this->glCode('6500')->id, 'annual' => 36_000_000],
            ['gl_account_id' => $this->glCode('6100')->id, 'annual' => 2_400_000, 'dimension' => 'department:3'],
        ]);
        app(PlanBudgets::class)->submit($plan, $this->owner);
    }

    /** @return list<array{string, array<string, mixed>}> */
    private function scenarios(): array
    {
        $h1 = ['from' => '2026-01-01', 'to' => '2026-06-30'];

        return [
            ['profit-loss', $h1 + ['monthly' => '1', 'compare' => 'previous']],
            ['profit-loss', $h1 + ['basis' => 'cash']],
            ['balance-sheet', ['to' => '2026-06-30', 'compare' => 'previous']],
            ['cash-flow-statement', $h1 + ['view' => 'indirect']],
            ['cash-flow-statement', $h1 + ['view' => 'direct']],
            ['trial-balance', ['to' => '2026-06-30']],
            ['general-ledger', $h1 + ['account' => 'REVENUE']],
            ['ageing-receivables', ['to' => '2026-06-30']],
            ['ageing-payables', ['to' => '2026-06-30']],
            ['vat', $h1],
            ['tax-summary', ['to' => '2026-06-30']],
            ['revenue', $h1 + ['view' => 'account', 'compare' => 'previous']],
            ['revenue', $h1 + ['view' => 'party']],
            ['revenue', $h1 + ['view' => 'category']],
            ['revenue', $h1 + ['view' => 'month']],
            ['revenue', $h1 + ['view' => 'dimension']],
            ['expenses', $h1 + ['view' => 'account']],
            ['expenses', $h1 + ['view' => 'movers', 'compare' => 'previous']],
            ['performance', $h1],
            ['budget-vs-actual', $h1 + ['view' => 'account']],
            ['budget-vs-actual', $h1 + ['view' => 'dimension']],
        ];
    }

    public function test_every_money_figure_with_a_drill_equals_the_journal_lines_behind_it(): void
    {
        $this->books();
        $runner = app(ReportRunner::class);
        $drill = app(DrillDown::class);
        $checked = 0;

        foreach ($this->scenarios() as [$key, $input]) {
            if (($input['account'] ?? null) === 'REVENUE') {
                $input['account'] = (string) $this->glCode('4100')->id;
            }
            $result = $runner->run($this->ctx, $key, $input, CarbonImmutable::parse('2026-11-09'));
            $this->assertNotEmpty($result->rows, "{$key} produced no rows");

            foreach ($result->rows as $row) {
                foreach ($result->columns as $col) {
                    if ($col['type'] !== 'money' || ! isset($row['drill'][$col['key']]) || $row['drill'][$col['key']] === []) {
                        continue;
                    }
                    $value = $row['cells'][$col['key']] ?? null;
                    if ($value === null) {
                        continue;
                    }
                    $params = $row['drill'][$col['key']];
                    $total = $drill->lines($this->ctx, $params)['total_cents'];
                    $this->assertSame((int) $value, $total, "{$key} {$this->describe($input)}: '{$row['label']}' / {$col['label']} shows {$value} but its journal lines total {$total} (".json_encode($params).')');
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(200, $checked, 'the harness should have walked a few hundred figures');
    }

    private function describe(array $input): string
    {
        return '['.collect($input)->map(fn ($v, $k) => "$k=$v")->implode(' ').']';
    }

    public function test_every_registered_report_is_walked_by_the_harness(): void
    {
        $this->books();
        $walked = collect($this->scenarios())->pluck(0)->unique()->all();
        sort($walked);
        $all = app(ReportRegistry::class)->keys();
        sort($all);
        $this->assertSame($all, $walked, 'a report was added without a drill-down check');
    }

    public function test_the_drill_page_says_whether_it_agrees_with_the_figure_it_was_opened_from(): void
    {
        $this->books();
        $r = app(ReportRunner::class)->run($this->ctx, 'profit-loss', ['from' => '2026-01-01', 'to' => '2026-06-30']);
        $row = $r->row('Net profit');
        $url = route('reports.drill', $row['drill']['total'] + ['figure' => $row['cells']['total']]);

        $this->inCompany($this->owner, $this->company)->get($url)->assertOk()->assertSee('Agrees with the figure on the report')->assertSee('JE-');

        $wrong = route('reports.drill', $row['drill']['total'] + ['figure' => $row['cells']['total'] + 1]);
        $this->inCompany($this->owner, $this->company)->get($wrong)->assertOk()->assertSee('out by')->assertDontSee('Agrees with the figure');
    }

    public function test_lines_link_to_their_journal_and_source_and_filters_are_validated(): void
    {
        $this->books();
        $page = $this->inCompany($this->owner, $this->company);

        $page->get(route('reports.drill', ['account' => $this->glCode('4100')->id, 'from' => '2026-01-01', 'to' => '2026-01-31', 'basis' => 'accrual']))
            ->assertOk()->assertSee('Transaction #');

        $page->get(route('reports.drill', ['class' => 'nonsense']))->assertSessionHasErrors('class');
        $page->get(route('reports.drill', ['from' => '2026-13-45']))->assertSessionHasErrors('from');
        $page->get(route('reports.drill', ['account' => 999999]))->assertNotFound();
    }
}
