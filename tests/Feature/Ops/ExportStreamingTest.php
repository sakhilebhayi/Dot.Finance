<?php

namespace Tests\Feature\Ops;

use App\Services\Accounting\PostingLine;
use App\Services\Reporting\Export\ReportCsv;
use App\Services\Reporting\Export\XlsxWriter;
use App\Services\Reporting\ReportRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;
use ZipArchive;

/** A general ledger export is produced one row at a time, and says exactly what the page's report says. */
class ExportStreamingTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private function input(): array
    {
        $this->buildCompanyBooks();
        foreach (range(1, 40) as $i) {
            $this->journal('2026-07-'.str_pad((string) (1 + $i % 27), 2, '0', STR_PAD_LEFT), 'Streamed '.$i, [
                new PostingLine('bank', debitCents: 100 * $i), new PostingLine('sales', creditCents: 100 * $i),
            ]);
        }

        return ['from' => '2026-01-01', 'to' => '2026-12-31', 'account' => (string) $this->glCode('1010')->id];
    }

    public function test_the_streamed_rows_are_the_rows_of_the_report(): void
    {
        $input = $this->input();
        $runner = app(ReportRunner::class);
        $today = CarbonImmutable::parse('2026-11-09');

        $page = $runner->run($this->ctx, 'general-ledger', $input, $today);
        $streamed = $runner->run($this->ctx, 'general-ledger', $input + ['stream' => '1'], $today);

        $this->assertSame([], $streamed->rows, 'nothing is held in the result');
        $this->assertNotNull($streamed->rowStream);
        $strip = fn (iterable $rows) => collect(is_array($rows) ? $rows : iterator_to_array($rows, false))->map(fn ($r) => ['type' => $r['type'], 'label' => $r['label'], 'cells' => $r['cells'], 'depth' => $r['depth'] ?? null])->values()->all();
        $this->assertSame($strip($page->rows), $strip($streamed->eachRow()), 'same rows, same order, same running balances');
    }

    public function test_csv_and_spreadsheet_written_from_the_stream_match_the_ones_written_from_the_array(): void
    {
        $input = $this->input();
        $runner = app(ReportRunner::class);
        $today = CarbonImmutable::parse('2026-11-09');
        $page = $runner->run($this->ctx, 'general-ledger', $input, $today);
        $streamed = $runner->run($this->ctx, 'general-ledger', $input + ['stream' => '1'], $today);

        $this->assertSame(ReportCsv::render($page), ReportCsv::render($streamed));

        $a = sys_get_temp_dir().'/stream-a-'.uniqid().'.xlsx';
        $b = sys_get_temp_dir().'/stream-b-'.uniqid().'.xlsx';
        $rowsA = XlsxWriter::write($page, $a);
        $rowsB = XlsxWriter::write($streamed, $b);
        $this->assertSame($rowsA, $rowsB);

        $sheet = function (string $path) {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            @unlink($path);

            return $xml;
        };
        $xmlA = $sheet($a);
        $this->assertSame($xmlA, $sheet($b));
        $this->assertSame($rowsA, substr_count($xmlA, '<row '), 'the sheet has the rows the writer counted');
        $this->assertFileDoesNotExist($a.'.sheet.part', 'the temporary sheet file is cleaned up');
    }
}
