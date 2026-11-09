<?php

namespace Tests\Feature\Reporting;

use App\Contracts\Reporting\OpenItemsProvider;
use App\Contracts\Reporting\TaxSummaryProvider;
use App\Models\Shared\TaxCode;
use App\Services\Accounting\PostingLine;
use App\Services\Reporting\NullOpenItemsProvider;
use App\Services\Reporting\ReportResult;
use App\Services\Reporting\ReportRunner;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;

class AnalysisReportsTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private function run_(string $key, array $input = []): ReportResult
    {
        return app(ReportRunner::class)->run($this->ctx, $key, $input, CarbonImmutable::parse('2026-11-09'));
    }

    // ---- ageing -------------------------------------------------------------------------------

    public function test_receivables_ageing_buckets_by_days_past_due_and_ties_to_the_ledger(): void
    {
        $this->buildCompanyBooks();
        $acme = $this->party('Acme Retail', 'customer', 30);
        $beta = $this->party('Beta Stores', 'customer', 14);
        $this->invoice('2026-03-01', 100_000, $acme, null, 6001);   // due 31 Mar: 91 days past on 30 Jun -> over 90
        $this->invoice('2026-05-15', 200_000, $acme, null, 6002);   // due 14 Jun: 16 days past -> 1 to 30
        $this->invoice('2026-06-25', 300_000, $beta, null, 6003);   // due 9 Jul: not yet due
        $this->invoice('2026-04-20', 50_000, $beta, null, 6004);    // due 4 May: 57 days -> 31 to 60

        $r = $this->run_('ageing-receivables', ['to' => '2026-06-30']);
        $this->assertSame([], $r->failedChecks());

        $acmeRow = $r->row('Acme Retail')['cells'];
        $this->assertEquals(['over' => 100_000, 'b30' => 200_000, 'current' => 0, 'b60' => 0, 'total' => 300_000], array_intersect_key($acmeRow, array_flip(['over', 'b30', 'current', 'b60', 'total'])));
        $betaRow = $r->row('Beta Stores')['cells'];
        $this->assertSame(300_000, $betaRow['current']);
        $this->assertSame(50_000, $betaRow['b60']);
        $this->assertSame(650_000, $r->row('Total')['cells']['total']);
        $this->assertSame('Not yet due', $r->columns[1]['label']);
    }

    public function test_money_received_settles_the_oldest_amounts_first_and_a_credit_with_nothing_to_settle_is_shown_as_unapplied(): void
    {
        $this->buildCompanyBooks();
        $acme = $this->party('Acme Retail', 'customer', 0);
        $this->invoice('2026-04-01', 100_000, $acme, null, 6101);
        $this->invoice('2026-05-01', 100_000, $acme, null, 6102);
        $this->receipt('2026-05-10', 130_000, $acme, 6101);

        $r = $this->run_('ageing-receivables', ['to' => '2026-06-30']);
        $this->assertSame(70_000, $r->row('Acme Retail')['cells']['total'], 'the first invoice is settled, 30,000 of the second');
        $this->assertSame(70_000, $r->row('Acme Retail')['cells']['b60'], 'the remainder is the May invoice: 60 days past on 30 June');

        $this->receipt('2026-06-10', 90_000, $acme);
        $over = $this->run_('ageing-receivables', ['to' => '2026-06-30']);
        $this->assertSame(-20_000, $over->row('Acme Retail')['cells']['total'], '20,000 more was received than was owed: an unapplied credit');
        $this->assertSame([], $over->failedChecks());
    }

    public function test_payables_ageing_and_a_bound_provider_replaces_the_ledger_derived_one(): void
    {
        $this->buildCompanyBooks();
        $s = $this->party('Landlord', 'supplier', 30);
        $this->bill('2026-05-01', 400_000, $s, 'Rent', null, 6201);
        $r = $this->run_('ageing-payables', ['to' => '2026-06-30']);
        $this->assertSame(400_000, $r->row('Landlord')['cells']['total']);
        $this->assertSame(400_000, $r->row('Landlord')['cells']['b30'], 'due 31 May, 30 days past on 30 June');
        $this->assertSame([], $r->failedChecks());

        // A module binds its own source: here nothing is known, so the report is empty and the ledger check goes red.
        $this->app->bind(OpenItemsProvider::class, NullOpenItemsProvider::class);
        $none = $this->run_('ageing-payables', ['to' => '2026-06-30']);
        $this->assertNull($none->row('Landlord'));
        $this->assertFalse($none->checks[0]['ok']);
        $this->assertSame(400_000, $none->checks[0]['difference_cents'] * -1);
    }

    // ---- VAT and tax --------------------------------------------------------------------------

    public function test_vat_summary_from_the_vat_accounts_and_tax_coded_lines(): void
    {
        $this->buildCompanyBooks();
        $c = $this->party('Acme Retail');
        $s = $this->party('Landlord', 'supplier');
        $code = TaxCode::create(['jurisdiction' => 'ZA', 'code' => 'ZA_VAT_STD', 'name' => 'Standard rate', 'kind' => 'standard', 'applies_to' => 'both', 'rate_ref' => 'ZA_VAT', 'box_output' => '1', 'box_input' => '15', 'valid_from' => '2018-04-01']);

        $this->journal('2026-03-03', 'Sale', [
            new PostingLine('ar', debitCents: 1_150_000, partyId: $c->id),
            new PostingLine('sales', creditCents: 1_000_000, taxCodeId: $code->id),
            new PostingLine('vat_output', creditCents: 150_000, taxCodeId: $code->id),
        ], 'invoice', 4001);
        $this->journal('2026-03-04', 'Purchase', [
            new PostingLine($this->glCode('6100')->id, debitCents: 200_000, taxCodeId: $code->id),
            new PostingLine('vat_input', debitCents: 30_000, taxCodeId: $code->id),
            new PostingLine('ap', creditCents: 230_000, partyId: $s->id),
        ], 'bill', 4002);

        $r = $this->run_('vat', ['from' => '2026-03-01', 'to' => '2026-03-31']);
        $this->assertSame(150_000, $r->cell('Output VAT charged on sales', 'tax'));
        $this->assertSame(30_000, $r->cell('Input VAT claimable on purchases', 'tax'));
        $this->assertSame(120_000, $r->cell('VAT payable for the period (output less input)', 'tax'));
        $coded = $r->row('ZA_VAT_STD Standard rate');
        $this->assertSame(['base' => 1_000_000 + 200_000, 'tax' => 120_000], $coded['cells']);
        $this->assertSame([], $r->failedChecks());
        $this->assertStringContainsString('not a VAT201', implode(' ', $r->header['assumptions']));
    }

    public function test_tax_summary_shows_ledger_balances_and_says_plainly_when_the_tax_module_is_not_connected(): void
    {
        $this->buildCompanyBooks();
        $c = $this->party('Acme Retail');
        $this->invoice('2026-03-03', 1_000_000, $c, null, 4101, 150_000);
        $r = $this->run_('tax-summary', ['to' => '2026-06-30']);
        $this->assertSame(150_000, $r->cell('VAT output (owed to SARS)', 'amount'));
        $this->assertSame('Calculated', $r->cell('VAT output (owed to SARS)', 'kind'));
        $this->assertStringContainsString('Tax module is not connected', implode(' ', $r->notes));

        $this->app->bind(TaxSummaryProvider::class, fn () => new class implements TaxSummaryProvider
        {
            public function summary(LedgerContext $context, string $from, string $to): ?array
            {
                return ['source' => 'Dot.Finance Tax', 'items' => [
                    ['label' => 'Provisional tax, second period', 'amount_cents' => 2_000_000, 'kind' => 'estimate', 'due_on' => '2026-08-31', 'status' => 'prepared'],
                    ['label' => 'VAT201 reference', 'amount_cents' => null, 'kind' => 'user_provided', 'due_on' => null],
                ]];
            }
        });
        $with = $this->run_('tax-summary', ['to' => '2026-06-30']);
        $this->assertSame('Estimate', $with->cell('Provisional tax, second period (prepared)', 'kind'));
        $this->assertSame('User provided', $with->cell('VAT201 reference', 'kind'));
    }

    // ---- revenue and expense analysis ---------------------------------------------------------

    public function test_revenue_by_account_category_party_month_and_dimension_all_total_the_same(): void
    {
        $this->buildCompanyBooks();
        $acme = $this->party('Acme Retail');
        $this->invoice('2026-04-20', 300_000, $acme, 'project:12', 3001);
        $this->invoice('2026-05-20', 200_000, $acme, null, 3002);

        $totals = [];
        foreach (['account', 'category', 'party', 'month', 'dimension'] as $view) {
            $r = $this->run_('revenue', ['from' => '2026-01-01', 'to' => '2026-06-30', 'view' => $view]);
            $totals[$view] = $r->row('Total income')['cells']['total'];
            $this->assertSame([], $r->failedChecks(), $view);
        }
        $this->assertSame([75_025_000 + 500_000], array_values(array_unique($totals)));

        $party = $this->run_('revenue', ['from' => '2026-01-01', 'to' => '2026-06-30', 'view' => 'party']);
        $this->assertSame(500_000, $party->cell('Acme Retail', 'total'));
        $this->assertSame(75_025_000, $party->cell('No customer or supplier', 'total'));

        $dim = $this->run_('revenue', ['from' => '2026-01-01', 'to' => '2026-06-30', 'view' => 'dimension']);
        $this->assertSame(300_000, $dim->cell('project:12', 'total'));
        $this->assertSame(500_000 - 300_000 + 75_025_000, $dim->cell('Unassigned', 'total'));

        $month = $this->run_('revenue', ['from' => '2026-01-01', 'to' => '2026-06-30', 'view' => 'month']);
        $this->assertSame(11_000_000, $month->cell('Feb 2026', 'total'));
        $this->assertSame(12_000_000 + 25_000, $month->cell('Mar 2026', 'total'), 'March: 12,000,000 sales and 25,000 interest');
    }

    public function test_top_n_groups_the_tail_and_movers_rank_by_money_changed(): void
    {
        $this->buildCompanyBooks();
        $r = $this->run_('expenses', ['from' => '2026-04-01', 'to' => '2026-06-30', 'view' => 'account', 'top' => '3']);
        $this->assertSame(2, collect($r->rows)->where('type', 'line')->count(), 'only rent and fuel have expenses');

        $movers = $this->run_('expenses', ['from' => '2026-04-01', 'to' => '2026-06-30', 'view' => 'movers', 'compare' => 'previous']);
        $lines = array_values(array_filter($movers->rows, fn ($row) => $row['type'] === 'line'));
        // Fuel grew: Q1 550k+500k+450k = 1,500,000, Q2 600k+650k+700k = 1,950,000. Rent is flat at 9,000,000 each quarter.
        $this->assertCount(1, $lines);
        $this->assertSame($this->categoryAccount('Fuel')->label(), $lines[0]['label']);
        $this->assertSame(450_000, $lines[0]['cells']['chg']);
        $this->assertSame(3000, $lines[0]['cells']['chg_pct'], '+30.00%');
        $this->assertSame('Up', $lines[0]['cells']['dir']);
    }

    public function test_department_and_project_performance_includes_what_is_unassigned(): void
    {
        $this->buildCompanyBooks();
        $acme = $this->party('Acme Retail');
        $this->invoice('2026-04-20', 900_000, $acme, 'project:12', 2001);
        $this->bill('2026-04-21', 400_000, $this->party('Landlord', 'supplier'), 'Rent', 'project:12', 2002);

        $r = $this->run_('performance', ['from' => '2026-04-01', 'to' => '2026-04-30']);
        $p = $r->row('project:12')['cells'];
        $this->assertSame(['income' => 900_000, 'expense' => 400_000, 'net' => 500_000, 'margin' => 5556], $p);
        $u = $r->row('Unassigned')['cells'];
        $this->assertSame([13_000_000, 3_000_000 + 600_000], [$u['income'], $u['expense']], 'April sales, rent and fuel from the cash ledger carry no project');
        $this->assertSame([], $r->failedChecks());
    }
}
