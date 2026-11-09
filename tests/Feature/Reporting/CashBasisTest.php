<?php

namespace Tests\Feature\Reporting;

use App\Models\Shared\PaymentAllocation;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Reporting\DrillDown;
use App\Services\Reporting\ReportResult;
use App\Services\Reporting\ReportRunner;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;

/**
 * The cash basis recognises the paid share of an invoice or bill on each payment date. See CashBasis for the method and
 * its limits; these tests pin the arithmetic, the exclusions and that drill-down still adds up to the figure.
 */
class CashBasisTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private function pl(string $from, string $to, string $basis): ReportResult
    {
        return app(ReportRunner::class)->run($this->ctx, 'profit-loss', ['from' => $from, 'to' => $to, 'basis' => $basis], CarbonImmutable::parse('2026-11-09'));
    }

    private function income(string $from, string $to, string $basis): int
    {
        return (int) $this->pl($from, $to, $basis)->cell('Total income', 'total');
    }

    public function test_an_invoice_is_recognised_as_it_is_paid_and_adds_up_when_fully_paid(): void
    {
        $this->buildCompanyBooks();
        $c = $this->party('Acme Retail');
        $this->invoice('2026-05-10', 1_000_000, $c, null, 7001, 150_000);
        $this->receipt('2026-06-05', 575_000, $c, 7001);
        $this->receipt('2026-07-20', 575_000, $c, 7001);

        $base = 14_000_000; // May sales from the cash ledger
        $this->assertSame($base + 1_000_000, $this->income('2026-05-01', '2026-05-31', 'accrual'));
        $this->assertSame($base, $this->income('2026-05-01', '2026-05-31', 'cash'), 'nothing paid in May');
        $this->assertSame(15_000_000 + 500_000, $this->income('2026-06-01', '2026-06-30', 'cash'), 'half of the net on the first payment');
        $this->assertSame(500_000, $this->income('2026-07-01', '2026-07-31', 'cash'));

        // Over the whole life the two bases agree to the cent.
        $this->assertSame($this->income('2026-01-01', '2026-12-31', 'accrual'), $this->income('2026-01-01', '2026-12-31', 'cash'));
    }

    public function test_recognised_amounts_add_up_exactly_whatever_the_rounding(): void
    {
        $this->buildCompanyBooks();
        $c = $this->party('Odd Amounts');
        // One document, two income lines (333 and 667 cents), paid in two equal halves.
        $this->journal('2026-03-03', 'Odd invoice', [
            new PostingLine('ar', debitCents: 1_000, partyId: $c->id),
            new PostingLine('sales', creditCents: 333, partyId: $c->id),
            new PostingLine('other_income', creditCents: 667, partyId: $c->id),
        ], 'invoice', 9001);
        $this->receipt('2026-03-10', 500, $c, 9001);
        $this->receipt('2026-03-20', 500, $c, 9001);

        $march = $this->pl('2026-03-01', '2026-03-31', 'cash');
        $accrual = $this->pl('2026-03-01', '2026-03-31', 'accrual');
        $this->assertSame($accrual->cell('Total income', 'total'), $march->cell('Total income', 'total'));
        $this->assertSame(333, $this->lineTotal('4000', '2026-03-03', '2026-03-31', 'cash', 333), 'the sales line comes to its exact amount');
    }

    /** Income of the Sales account over the period, minus the cash-ledger sales posted to the category accounts. */
    private function lineTotal(string $code, string $from, string $to, string $basis, int $expectDocument): int
    {
        $row = $this->pl($from, $to, $basis)->row('4000 Sales');

        return (int) ($row['cells']['total'] ?? 0);
    }

    public function test_a_half_paid_invoice_in_the_middle_of_a_period_is_cut_at_the_dates(): void
    {
        $this->buildCompanyBooks();
        $c = $this->party('Cut Dates');
        $this->invoice('2026-04-01', 1_000, $c, null, 9101);
        $this->receipt('2026-04-15', 250, $c, 9101);
        $this->receipt('2026-05-15', 750, $c, 9101);

        $sales = fn (string $from, string $to) => (int) ($this->pl($from, $to, 'cash')->row('4000 Sales')['cells']['total'] ?? 0);
        $this->assertSame(0, $sales('2026-04-01', '2026-04-14'));
        $this->assertSame(250, $sales('2026-04-01', '2026-04-30'));
        $this->assertSame(1_000, $sales('2026-04-01', '2026-05-31'));
        $this->assertSame(750, $sales('2026-05-01', '2026-05-31'));
    }

    public function test_bills_are_expensed_when_paid_and_documents_with_nothing_to_collect_are_taken_as_posted(): void
    {
        $this->buildCompanyBooks();
        $s = $this->party('Landlord', 'supplier');
        $this->bill('2026-03-02', 600_000, $s, 'Rent', null, 8001);
        $this->payBill('2026-04-02', 600_000, $s, 8001);

        $expenses = fn (string $from, string $to, string $basis) => (int) $this->pl($from, $to, $basis)->cell('Total expenses', 'total');
        $this->assertSame(4_150_000, $expenses('2026-03-01', '2026-03-31', 'accrual'), 'accrual: March has rent 3,000,000, fuel 550,000 and the bill 600,000');
        $this->assertSame(3_550_000, $expenses('2026-03-01', '2026-03-31', 'cash'), 'cash: March has only the cash-ledger rent and fuel');
        $this->assertSame(3_000_000 + 600_000 + 600_000, $expenses('2026-04-01', '2026-04-30', 'cash'), 'cash: April carries the bill, paid that month');
        $this->assertSame(3_000_000 + 600_000, $expenses('2026-04-01', '2026-04-30', 'accrual'));

        // An invoice with no receivable line (a cash sale posted as an invoice) is simply taken as posted.
        $this->journal('2026-04-10', 'Counter sale', [
            new PostingLine('bank', debitCents: 40_000), new PostingLine('sales', creditCents: 40_000),
        ], 'invoice', 9201);
        $this->assertSame(40_000, (int) $this->pl('2026-04-10', '2026-04-10', 'cash')->row('4000 Sales')['cells']['total']);
    }

    public function test_a_voided_invoice_leaves_no_trace_on_either_basis_and_released_payments_recognise_nothing(): void
    {
        $this->buildCompanyBooks();
        $c = $this->party('Voided');
        $entry = $this->invoice('2026-05-02', 800_000, $c, null, 9301);
        LedgerContext::run($this->ctx, fn () => app(JournalPoster::class)->reverse($entry, 'Voided', CarbonImmutable::parse('2026-05-03')));

        foreach (['accrual', 'cash'] as $basis) {
            $this->assertNull($this->pl('2026-05-01', '2026-05-31', $basis)->row('4000 Sales'), "{$basis}: a voided invoice recognises nothing");
        }

        $d = $this->party('Released');
        $this->invoice('2026-06-02', 100_000, $d, null, 9302);
        $this->receipt('2026-06-03', 100_000, $d, 9302);
        $this->assertSame(100_000, (int) $this->pl('2026-06-01', '2026-06-30', 'cash')->row('4000 Sales')['cells']['total']);
        PaymentAllocation::query()->forContext($this->ctx)->update(['released_at' => now()]);
        $this->assertNull($this->pl('2026-06-01', '2026-06-30', 'cash')->row('4000 Sales'), 'a released allocation settles nothing');
    }

    public function test_drill_down_on_the_cash_basis_adds_up_to_the_figure(): void
    {
        $this->buildCompanyBooks();
        $c = $this->party('Drill Cash');
        $this->invoice('2026-05-10', 1_000_000, $c, null, 9401, 150_000);
        $this->receipt('2026-06-05', 575_000, $c, 9401);

        $r = $this->pl('2026-06-01', '2026-06-30', 'cash');
        $row = $r->row('4000 Sales');
        $this->assertSame(500_000, $row['cells']['total']);
        $drill = app(DrillDown::class)->lines($this->ctx, $row['drill']['total']);
        $this->assertSame(500_000, $drill['total_cents']);
        $this->assertSame(1, $drill['count']);
        $this->assertSame(-500_000, $drill['lines']->items()[0]['recognised_cents'], 'recognised in debit-minus-credit terms: a credit of 500,000');

        $net = $r->row('Net profit');
        $this->assertSame($net['cells']['total'], app(DrillDown::class)->lines($this->ctx, $net['drill']['total'])['total_cents']);
    }

    public function test_the_page_states_the_method_and_marks_the_trial_balance_check_as_not_comparable(): void
    {
        $this->buildCompanyBooks();
        $this->inCompany($this->owner, $this->company)->get(route('reports.profit-loss', ['preset' => 'this_year', 'basis' => 'cash']))
            ->assertOk()->assertSee('Cash basis: invoices and bills count for the share that has been paid')->assertSee('not comparable');
    }
}
