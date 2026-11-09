<?php

namespace Tests\Feature\Reporting;

use App\Jobs\Reporting\GenerateReportExport;
use App\Models\Reporting\ReportExport;
use App\Services\Accounting\PostingLine;
use App\Services\Reporting\Export\ReportCsv;
use App\Services\Reporting\Export\XlsxWriter;
use App\Services\Reporting\ExportService;
use App\Services\Reporting\ReportResult;
use App\Services\Reporting\ReportRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;
use ZipArchive;

class ExportsTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private const INPUT = ['from' => '2026-01-01', 'to' => '2026-06-30'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function report(string $key = 'profit-loss'): ReportResult
    {
        return app(ReportRunner::class)->run($this->ctx, $key, self::INPUT, CarbonImmutable::parse('2026-11-09'));
    }

    private function as_(string $role = 'owner'): static
    {
        return $this->inCompany($role === 'owner' ? $this->owner : $this->memberOf($this->company, $role), $this->company);
    }

    // ---- CSV ---------------------------------------------------------------------------------

    public function test_the_csv_carries_the_report_header_plain_decimals_and_the_checks(): void
    {
        $this->buildCompanyBooks();
        $csv = ReportCsv::render($this->report());

        $this->assertStringContainsString("\"Profit and loss\"\n", $csv);
        $this->assertStringContainsString("Ledger,\"Acme (Pty) Ltd\"\n", $csv);
        $this->assertStringContainsString("Period,\"1 Jan 2026 to 30 Jun 2026\"\n", $csv);
        $this->assertStringContainsString("Currency,ZAR\n", $csv);
        $this->assertStringContainsString("Basis,Accrual\n", $csv);
        $this->assertStringContainsString('"Generated at",', $csv);
        $this->assertStringContainsString('"Posting rules",v1', $csv);
        $this->assertStringContainsString('Assumption,', $csv);
        $this->assertMatchesRegularExpression('/^"Net profit",535750\.00,/m', $csv, 'money is a plain decimal in the major unit');
        $this->assertStringContainsString('Check,"Ties to the trial balance",OK,0.00', $csv);
        $this->assertDoesNotMatchRegularExpression('/"-?\d{1,3}(,\d{3})+\.\d\d"/', $csv, 'no thousands separators in figures (they would have to be quoted)');
    }

    public function test_text_a_spreadsheet_could_run_as_a_formula_is_neutralised_but_numbers_are_left_alone(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil\")", ReportCsv::safe('=HYPERLINK("http://evil")'));
        $this->assertSame("'+1+1", ReportCsv::safe('+1+1'));
        $this->assertSame("'@SUM(A1)", ReportCsv::safe('@SUM(A1)'));
        $this->assertSame("'-cmd", ReportCsv::safe('-cmd'));
        $this->assertSame('-12.34', ReportCsv::safe('-12.34'), 'a negative number is a number');
        $this->assertSame('Rent', ReportCsv::safe('Rent'));

        $r = new ReportResult('x', 'X', ['ledger' => '=1+1', 'currency' => 'ZAR'], [['key' => 'label', 'label' => 'Name', 'type' => 'text', 'projected' => false], ['key' => 'v', 'label' => 'Amount', 'type' => 'money', 'projected' => false]],
            [['type' => 'line', 'label' => '@evil', 'depth' => 1, 'cells' => ['v' => -1234], 'drill' => []]]);
        $csv = ReportCsv::render($r);
        $this->assertStringContainsString("Ledger,'=1+1", $csv);
        $this->assertStringContainsString("'@evil,-12.34", $csv);
    }

    public function test_a_small_csv_streams_straight_back_and_needs_the_export_capability(): void
    {
        $this->buildCompanyBooks();

        $response = $this->as_()->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv'] + self::INPUT);
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('profit-loss-2026-06-30.csv', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('"Net profit",535750.00', $response->streamedContent());
        $this->assertSame(0, ReportExport::withoutGlobalScopes()->count(), 'a small export leaves no row behind');

        $this->as_('viewer')->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv'] + self::INPUT)->assertForbidden();
        $this->as_('editor')->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv'] + self::INPUT)->assertOk();
        $this->as_()->post(route('reports.exports.store'), ['report' => 'not-a-report', 'format' => 'csv'])->assertSessionHasErrors('report');
        $this->as_()->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'pdf'])->assertSessionHasErrors('format');
    }

    // ---- XLSX --------------------------------------------------------------------------------

    public function test_the_spreadsheet_is_a_valid_package_whose_parts_parse_and_hold_the_figures(): void
    {
        $this->buildCompanyBooks();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $rows = XlsxWriter::write($this->report(), $path);
        $this->assertGreaterThan(10, $rows);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $xml = $zip->getFromName($part);
            $this->assertNotFalse($xml, "{$part} is in the package");
            $doc = new \DOMDocument;
            $this->assertTrue($doc->loadXML($xml), "{$part} is well-formed XML");
        }
        $sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertCount($rows, $sheet->sheetData->row);
        $text = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('Net profit', $text);
        $this->assertStringContainsString('<v>535750.00</v>', $text, 'a figure is stored as a number, not as text');
        $this->assertStringContainsString('Profit and loss', $text);
        $zip->close();
        @unlink($path);
        $this->assertFileDoesNotExist($path.'.part');
    }

    public function test_the_spreadsheet_escapes_markup_and_never_writes_a_formula(): void
    {
        $r = new ReportResult('x', 'Q & A <b>', ['ledger' => '=SUM(A1)', 'currency' => 'ZAR'], [['key' => 'label', 'label' => 'Name', 'type' => 'text', 'projected' => false]],
            [['type' => 'line', 'label' => "=cmd|' /C calc'!A0 <script> \x01", 'depth' => 1, 'cells' => [], 'drill' => []]]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        XlsxWriter::write($r, $path);
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertTrue((new \DOMDocument)->loadXML($sheet));
        $this->assertStringNotContainsString('<f>', $sheet, 'no formula element');
        $this->assertStringContainsString('t="inlineStr"', $sheet);
        $this->assertStringNotContainsString('<script>', $sheet);
        $this->assertStringNotContainsString("\x01", $sheet);
        $zip->close();
        @unlink($path);
    }

    public function test_a_small_spreadsheet_downloads_through_the_route(): void
    {
        $this->buildCompanyBooks();
        $response = $this->as_()->post(route('reports.exports.store'), ['report' => 'balance-sheet', 'format' => 'xlsx', 'to' => '2026-06-30']);
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $tmp = tempnam(sys_get_temp_dir(), 'dl');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $this->assertStringContainsString('Total assets', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($tmp);
    }

    // ---- queued exports -------------------------------------------------------------------------

    public function test_a_large_export_is_queued_and_asking_again_returns_the_same_export_and_the_same_file(): void
    {
        $this->buildCompanyBooks();
        config(['reporting.export_queue_threshold_rows' => 1]);
        Queue::fake();

        $first = $this->as_()->post(route('reports.exports.store'), ['report' => 'general-ledger', 'format' => 'csv'] + self::INPUT);
        $export = ReportExport::withoutGlobalScopes()->firstOrFail();
        $first->assertRedirect(route('reports.exports.show', $export));
        Queue::assertPushed(GenerateReportExport::class, 1);

        $this->as_()->post(route('reports.exports.store'), ['report' => 'general-ledger', 'format' => 'csv'] + self::INPUT)->assertRedirect(route('reports.exports.show', $export));
        $this->assertSame(1, ReportExport::withoutGlobalScopes()->count(), 'the same ask is the same export');

        // Run the job, then run it again: still one file, one attempt.
        $job = new GenerateReportExport($export->id, null, $this->company->id);
        $job->handle(app(ExportService::class));
        $job->handle(app(ExportService::class));
        $export->refresh();
        $this->assertSame('done', $export->status);
        $this->assertSame(1, $export->attempts, 'a second run of a finished export does nothing');
        $this->assertCount(1, Storage::disk('local')->allFiles('report-exports'));
        $this->assertSame(hash('sha256', Storage::disk('local')->get($export->path)), $export->sha256);
        $this->assertGreaterThan(5, $export->row_count);

        // Asking after it is done returns it without queuing a new job.
        Queue::fake();
        $this->as_()->post(route('reports.exports.store'), ['report' => 'general-ledger', 'format' => 'csv'] + self::INPUT)->assertRedirect(route('reports.exports.show', $export));
        Queue::assertNothingPushed();
    }

    public function test_the_status_page_offers_a_signed_download_and_only_to_people_who_may_export(): void
    {
        $this->buildCompanyBooks();
        config(['reporting.export_queue_threshold_rows' => 1]);
        $this->as_()->post(route('reports.exports.store'), ['report' => 'profit-loss', 'format' => 'csv', 'queue' => '1'] + self::INPUT);
        $export = ReportExport::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('done', $export->status, 'with the sync queue the job has run');

        $page = $this->as_()->get(route('reports.exports.show', $export));
        $page->assertOk()->assertSee('Download')->assertSee('SHA-256')->assertSee($export->sha256);

        $signed = URL::temporarySignedRoute('reports.exports.download', now()->addMinutes(5), ['reportExport' => $export->id]);
        $download = $this->as_()->get($signed);
        $download->assertOk();
        $this->assertStringContainsString('"Net profit",535750.00', $download->streamedContent());
        $this->assertSame($export->sha256, $download->headers->get('X-Content-SHA256'));

        $this->as_()->get(route('reports.exports.download', $export))->assertForbidden();                      // unsigned
        $this->as_()->get(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $signed))->assertForbidden(); // tampered
        $this->as_('viewer')->get($signed)->assertForbidden();                                                    // signed, but not allowed to export
        $this->as_('viewer')->get(route('reports.exports.show', $export))->assertForbidden();
        $this->travel(2)->hours();
        $this->as_()->get($signed)->assertForbidden();                                                            // expired
    }

    public function test_a_failed_export_is_retried_in_place_and_a_new_posting_makes_a_new_export(): void
    {
        $this->buildCompanyBooks();
        Queue::fake();
        $service = app(ExportService::class);

        $export = $service->request($this->ctx, $this->owner, 'profit-loss', 'csv', self::INPUT);
        $good = $export->params;
        $export->forceFill(['params' => ['from' => '2026-06-30', 'to' => '2026-01-01']])->save(); // the period now ends before it starts
        try {
            $service->generate($export);
            $this->fail('expected the run to fail');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('failed', $export->refresh()->status);
        $this->assertNotEmpty($export->error);

        $export->forceFill(['params' => $good])->save();
        $again = $service->request($this->ctx, $this->owner, 'profit-loss', 'csv', self::INPUT);
        $this->assertSame($export->id, $again->id, 'retrying does not create a second row');
        $this->assertSame('queued', $again->status);
        $service->generate($again);
        $this->assertSame('done', $again->refresh()->status);
        $this->assertCount(1, Storage::disk('local')->allFiles('report-exports'));

        // New books, new export: the old file is not offered as the current one.
        $this->journal('2026-06-10', 'Late adjustment', [
            new PostingLine('bank', debitCents: 1_000), new PostingLine('sales', creditCents: 1_000),
        ]);
        $fresh = $service->request($this->ctx, $this->owner, 'profit-loss', 'csv', self::INPUT);
        $this->assertNotSame($export->id, $fresh->id);
        $service->generate($fresh);
        $this->assertStringContainsString('"Net profit",535760.00', Storage::disk('local')->get($fresh->refresh()->path));
        $this->assertStringContainsString('"Net profit",535750.00', Storage::disk('local')->get($export->refresh()->path), 'the earlier file still describes the earlier books');
    }

    public function test_expired_exports_are_pruned_with_their_files(): void
    {
        $this->buildCompanyBooks();
        $service = app(ExportService::class);
        $export = $service->request($this->ctx, $this->owner, 'trial-balance', 'csv', ['to' => '2026-06-30']);
        $export = $service->generate($export);
        $path = $export->path;
        Storage::disk('local')->assertExists($path);

        $this->assertSame(0, $service->prune());
        $this->travel(config('reporting.export_retention_days') + 1)->days();
        $this->assertSame(1, $service->prune());
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, ReportExport::withoutGlobalScopes()->count());
    }
}
