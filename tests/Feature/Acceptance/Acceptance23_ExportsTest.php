<?php

namespace Tests\Feature\Acceptance;

use App\Models\Reporting\ReportExport;
use Illuminate\Support\Facades\URL;
use ZipArchive;

/**
 * Owner criterion 23: users can export relevant reports (CSV, XLSX and print). Every export is for people who may export, carries the
 * header the owner asked for (ledger, period, currency, basis, when generated, rule versions, assumptions, reconciliation checks),
 * has the same figures as the screen, is safe to open in a spreadsheet, and a large one is queued, signed and checksummed.
 */
class Acceptance23_ExportsTest extends AcceptanceCase
{
    private const REPORTS = ['profit-loss', 'balance-sheet', 'trial-balance', 'cash-flow-statement', 'ageing-receivables', 'ageing-payables', 'vat', 'general-ledger', 'expenses', 'revenue'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMonth();
    }

    private function export(string $report, string $format = 'csv', array $more = []): \Illuminate\Testing\TestResponse
    {
        return $this->asOwner()->post(route('reports.exports.store'), ['report' => $report, 'format' => $format, 'from' => '2026-11-01', 'to' => '2026-11-30'] + $more);
    }

    public function test_every_core_report_exports_to_csv_with_ledger_period_currency_basis_and_checks_in_its_header(): void
    {
        foreach (self::REPORTS as $report) {
            $response = $this->export($report)->assertOk();
            $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'), $report);
            $this->assertStringContainsString($report, $response->headers->get('Content-Disposition'));
            $csv = $response->streamedContent();
            $this->assertStringContainsString('Ledger,"Harbour Trading (Pty) Ltd"', $csv, "{$report}: ledger");
            $this->assertMatchesRegularExpression('/^Period,"[^"]+"/m', $csv, "{$report}: period");
            $this->assertStringContainsString('Currency,ZAR', $csv, "{$report}: currency");
            $this->assertMatchesRegularExpression('/^Basis,(Accrual|Cash)/m', $csv, "{$report}: basis");
            $this->assertStringContainsString('"Generated at",', $csv, "{$report}: generated at");
            $this->assertStringContainsString('"Posting rules",v1', $csv, "{$report}: rule versions");
            $this->assertDoesNotMatchRegularExpression('/"-?\d{1,3}(,\d{3})+\.\d\d"/', $csv, "{$report}: no thousands separators in figures");
        }
    }

    public function test_the_csv_carries_the_same_figures_as_the_report_and_the_screen_and_its_checks(): void
    {
        $csv = $this->export('profit-loss')->streamedContent();
        $this->assertMatchesRegularExpression('/^"Net profit",15280\.00,/m', $csv);
        $this->assertMatchesRegularExpression('/^"Total income",30535\.00,/m', $csv);
        $this->assertStringContainsString('Check,"Ties to the trial balance",OK,0.00', $csv);
        $balance = $this->export('balance-sheet', 'csv', ['to' => '2026-11-30'])->streamedContent();
        $this->assertMatchesRegularExpression('/^"Total assets",129280\.00$/m', $balance);
        $this->asOwner()->get(route('reports.profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertSee('15,280.00')->assertSee('30,535.00');
    }

    public function test_the_cash_basis_export_says_cash_in_its_header(): void
    {
        $csv = $this->export('profit-loss', 'csv', ['basis' => 'cash'])->streamedContent();
        $this->assertMatchesRegularExpression('/^Basis,Cash/m', $csv);
        $this->assertDoesNotMatchRegularExpression('/^"Net profit",15280\.00,/m', $csv, 'a different basis is a different profit');
    }

    public function test_a_spreadsheet_export_is_a_valid_package_with_numbers_as_numbers(): void
    {
        $response = $this->export('profit-loss', 'xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $tmp = tempnam(sys_get_temp_dir(), 'dl');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertTrue((new \DOMDocument)->loadXML($sheet));
        $this->assertStringContainsString('<v>15280.00</v>', $sheet, 'net profit is a number cell');
        $this->assertStringContainsString('Harbour Trading', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet, 'no formulas');
        $zip->close();
        @unlink($tmp);
    }

    public function test_the_print_view_shows_the_same_header_and_figures(): void
    {
        $html = $this->asOwner()->get(route('reports.profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30', 'print' => 1]))->assertOk()->getContent();
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        foreach (['Harbour Trading (Pty) Ltd', '1 Nov 2026 to 30 Nov 2026', 'ZAR', 'Accrual', '15,280.00'] as $needle) {
            $this->assertStringContainsString($needle, $text, "the print view shows {$needle}");
        }
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $html, 'no inline style');
    }

    public function test_the_modules_own_exports_are_csv_with_a_title_the_period_and_the_basis(): void
    {
        $ar = $this->asOwner()->get(route('sales.receivables.csv'))->assertOk()->getContent();
        $this->assertStringContainsString('Receivables ageing', $ar);
        $this->assertStringContainsString('as at 2026-11-30', $ar);
        $this->assertStringContainsString('basis: days past due date', $ar);
        $this->assertStringContainsString('currency: base currency', $ar);
        $ap = $this->asOwner()->get(route('purchases.ap.index', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Ages measured at 2026-11-30', $ap);
        $this->assertStringContainsString('Base currency amounts', $ap);
        $stmt = $this->asOwner()->get(route('sales.customers.statement.csv', $this->acme))->assertOk()->getContent();
        $this->assertNotSame('', $stmt);
    }

    public function test_only_people_who_may_export_can_and_a_large_export_is_queued_signed_and_checksummed(): void
    {
        $this->asMember($this->memberAs('viewer'))->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30'])->assertForbidden();
        $this->asMember($this->memberAs('approver'))->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30'])->assertForbidden();
        $this->asMember($this->memberAs('bookkeeper'))->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30'])->assertOk();
        $this->asMember($this->memberAs('viewer'))->get(route('sales.receivables.csv'))->assertForbidden();

        config(['reporting.export_queue_threshold_rows' => 1]);
        $this->export('general-ledger', 'csv', ['queue' => '1']);
        $export = ReportExport::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame('done', $export->status);
        $this->assertSame(64, strlen($export->sha256));
        $signed = URL::temporarySignedRoute('reports.exports.download', now()->addMinutes(5), ['reportExport' => $export->id]);
        $this->asOwner()->get($signed)->assertOk()->assertHeader('X-Content-SHA256', $export->sha256);
        $this->asOwner()->get(route('reports.exports.download', $export))->assertForbidden();
        $this->assertCount(1, $this->auditRows('exported') ?: $this->auditRows(null, 'report_export'), 'the export is on the audit trail');
    }

    public function test_another_ledgers_export_cannot_be_opened_or_downloaded(): void
    {
        config(['reporting.export_queue_threshold_rows' => 1]);
        $this->export('general-ledger', 'csv', ['queue' => '1']);
        $export = ReportExport::query()->forContext($this->ledger)->firstOrFail();
        $other = $this->user();
        $company = $this->company($other, 'Elsewhere');
        $this->enableAccounting(\App\Support\Ledger\LedgerContext::company($company), 'ZAR');
        $signed = URL::temporarySignedRoute('reports.exports.download', now()->addMinutes(5), ['reportExport' => $export->id]);
        $this->inCompany($other, $company)->get($signed)->assertNotFound();
        $this->inCompany($other, $company)->get(route('reports.exports.show', $export))->assertNotFound();
    }
}
