<?php

namespace Tests\Feature\Integration;

use App\Contracts\Integrations\PayrollSummarySink;
use App\Contracts\Planning\PayrollCommitmentsProvider;
use App\Contracts\Planning\TaxObligationsProvider;
use App\Contracts\Reporting\OpenItemsProvider;
use App\Contracts\Reporting\TaxSummaryProvider;
use App\Models\Accounting\LedgerSetting;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Team;
use App\Services\Integrations\Hr\PayrollSummary;
use App\Services\Planning\CashProjection;
use App\Services\Reporting\ReportRunner;
use App\Services\Sales\Support\VatStatus;
use App\Services\Tax\Exceptions\ImportRejected;
use App\Services\Tax\TaxProfiles;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsAMonth;
use Tests\TestCase;

/** The providers Reporting and Planning were built against, answered by Sales, Purchases and Tax. */
class ReportingPlanningWiringTest extends TestCase
{
    use RefreshDatabase;
    use RunsAMonth;

    public function test_open_items_are_the_documents_with_their_numbers_and_due_dates(): void
    {
        $this->runMonth();
        $provider = app(OpenItemsProvider::class);
        $today = CarbonImmutable::parse('2026-11-30');

        $receivable = collect($provider->openItems($this->ledger, 'receivable', $today))->keyBy('reference');
        $this->assertSame(['INV-00001'], $receivable->keys()->all(), 'Beta\'s invoice is settled; Acme owes the rest of INV-00001');
        $item = $receivable['INV-00001'];
        $this->assertSame(900_000, $item->openCents);
        $this->assertSame('2026-12-03', $item->dueOn);
        $this->assertSame('Acme Retail', $item->partyName);
        $this->assertSame($this->acme->id, $item->partyId);
        $this->assertStringContainsString('/sales/documents/'.$this->inv1->id, (string) $item->href);

        $payable = collect($provider->openItems($this->ledger, 'payable', $today))->keyBy('reference');
        $this->assertSame(['BILL-2026-00003'], $payable->keys()->all());
        $this->assertSame(230_000, $payable['BILL-2026-00003']->openCents);
        $this->assertSame('2026-12-20', $payable['BILL-2026-00003']->dueOn);

        // an earlier date cannot be read from today's balances: it is derived from the postings, and still ties
        $then = collect($provider->openItems($this->ledger, 'receivable', CarbonImmutable::parse('2026-11-05')));
        $this->assertSame(1_400_000 + 2_300_000, $then->sum('openCents'), 'on 5 November both invoices were open');
        $ageing = app(ReportRunner::class)->run($this->ledger, 'ageing-receivables', ['from' => '2026-11-01', 'to' => '2026-11-05'], CarbonImmutable::parse('2026-11-30'));
        $this->assertSame([], array_map(fn ($c) => $c['label'].': '.$c['detail'], $ageing->failedChecks()));
        $this->assertSame(3_700_000, $ageing->cell('Total', 'total'));
    }

    public function test_payment_history_comes_from_the_documents_that_were_settled(): void
    {
        $this->runMonth();
        $this->receive($this->acme, 900_000, '2026-11-20', $this->inv1, 'ACME FINAL'); // INV-1 due 3 Dec, paid 20 Nov: 13 days early
        $history = app(OpenItemsProvider::class)->settlementHistory($this->ledger, 'receivable', CarbonImmutable::parse('2026-11-30'));

        $this->assertSame(1, $history[$this->acme->id]['settled']);
        $this->assertSame(1, $history[$this->beta->id]['settled']);
        $this->assertSame(-17, $history[$this->beta->id]['avg_days_late'], 'INV-2 was due 5 December and settled from the statement line of 18 November');
        $this->assertSame(-13, $history[$this->acme->id]['avg_days_late']);
    }

    public function test_tax_and_payroll_reach_the_cash_projection_from_the_tax_module(): void
    {
        $this->runMonth(bank: false);
        $this->artisan('finance:tax:calendar')->assertExitCode(0);
        $from = '2026-11-30';
        $to = '2027-02-28';

        $obligations = app(TaxObligationsProvider::class)->obligations($this->ledger, $from, $to);
        $vat = collect($obligations)->first(fn ($o) => str_starts_with($o['label'], 'VAT201 payment for'));
        $this->assertNotNull($vat, json_encode($obligations));
        $this->assertSame(321_000, $vat['amount_cents'], 'the VAT201 for November: field 20');
        $this->assertSame('estimate', $vat['kind'], 'not prepared yet, so an estimate');

        $commitments = app(PayrollCommitmentsProvider::class)->commitments($this->ledger, $from, $to);
        $this->assertCount(1, $commitments);
        $this->assertSame(750_000, $commitments[0]['amount_cents'], 'PAYE 6,000 + UIF 1,000 + SDL 500 for November');
        $this->assertSame('user_provided', $commitments[0]['kind'], 'typed in, not imported');

        $projection = app(CashProjection::class)->project($this->ledger, LedgerSetting::query()->forContext($this->ledger)->first(), CarbonImmutable::parse($from));
        $notes = implode(' ', $projection['notes']);
        $this->assertStringNotContainsString('No tax payments are included', $notes);
        $this->assertStringNotContainsString('No payroll is included', $notes);
        $sources = collect($projection['events'])->pluck('source')->unique()->sort()->values()->all();
        $this->assertContains('tax', $sources);
        $this->assertContains('payroll', $sources);
        $this->assertContains('invoice', $sources);
        $this->assertContains('bill', $sources);
    }

    public function test_the_tax_summary_report_shows_the_tax_modules_calendar(): void
    {
        $this->runMonth(bank: false);
        $this->assertNotNull(app(TaxSummaryProvider::class)->summary($this->ledger, '2026-11-01', '2026-12-31'));

        $report = app(ReportRunner::class)->run($this->ledger, 'tax-summary', ['from' => '2026-12-01', 'to' => '2026-12-31'], CarbonImmutable::parse('2026-11-30'));
        $this->assertStringNotContainsString('not connected to reporting', implode(' ', $report->notes));
    }

    public function test_a_payroll_summary_from_hr_lands_as_a_draft_run(): void
    {
        $this->runMonth(bank: false);
        $summary = PayrollSummary::fromArray([
            'company_uuid' => $this->company->infodot_team_uuid, 'run_id' => 'HR-2026-12', 'period_start' => '2026-12-01', 'period_end' => '2026-12-31', 'pay_date' => '2026-12-25',
            'currency' => 'ZAR', 'employees' => 4, 'totals' => ['gross_cents' => 5_200_000, 'net_cents' => 4_500_000, 'paye_cents' => 620_000, 'uif_employee_cents' => 52_000, 'uif_employer_cents' => 52_000, 'sdl_cents' => 52_000, 'eti_cents' => 0],
        ]);

        app(PayrollSummarySink::class)->accept($this->ledger, $summary);

        $run = TaxPayrollRun::query()->forContext($this->ledger)->where('period_month', '2026-12-01')->firstOrFail();
        $this->assertSame('draft', $run->status);
        $this->assertSame('hr_import', $run->source);
        $this->assertSame(620_000, $run->paye_cents);

        // the same summary again changes nothing; a summary for another company is refused
        app(PayrollSummarySink::class)->accept($this->ledger, $summary);
        $this->assertSame(1, TaxPayrollRun::query()->forContext($this->ledger)->where('period_month', '2026-12-01')->count());
        $other = $this->company($this->user(), 'Other Co');
        $this->expectException(ImportRejected::class);
        app(PayrollSummarySink::class)->accept(LedgerContext::company($other), $summary);
    }

    public function test_the_tax_profile_is_the_one_answer_to_whether_the_business_charges_vat(): void
    {
        $this->setUpIntegration();
        $status = app(VatStatus::class);

        $this->assertTrue($status->isRegistered($this->ledger));
        $this->assertSame('4123456789', $status->vatNumber($this->ledger));

        // the sales profile says "not registered" (setUpIntegration), the purchases setting says nothing: the Tax profile decides for both
        $invoice = $this->issueInvoice($this->acme, [['description' => 'Work', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-02');
        $this->assertSame(15_000, $invoice->tax_cents);
        $bill = $this->postBill($this->paperCo, '2026-11-02', [$this->billLine()]);
        $this->assertSame(60_000, $bill->claimable_tax_cents);

        // deregistration in the Tax profile switches both off
        LedgerContext::run($this->ledger, fn () => app(TaxProfiles::class)->save($this->ledger, [
            'jurisdiction' => 'ZA', 'legal_entity' => 'company', 'tax_regime' => 'standard', 'vat_registered' => false, 'financial_year_end_month' => 2,
            'provisional_taxpayer' => true, 'accounting_basis' => 'accrual', 'employs_staff' => true,
        ], $this->owner));
        $this->assertFalse(app(VatStatus::class)->isRegistered($this->ledger));
        $bill2 = $this->postBill($this->paperCo, '2026-11-03', [$this->billLine(['unit_price_cents' => 300_000])]);
        $this->assertSame(0, $bill2->claimable_tax_cents, 'no input VAT is claimed once the Tax profile says the business is not a vendor');
        $this->assertInstanceOf(Team::class, $this->company);
    }
}
