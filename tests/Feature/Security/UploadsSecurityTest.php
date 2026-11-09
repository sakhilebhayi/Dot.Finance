<?php

namespace Tests\Feature\Security;

use App\Models\Shared\Attachment;
use App\Services\Bank\Parsing\CsvStatementReader;
use App\Services\Bank\Parsing\OfxStatementParser;
use App\Services\Bank\Parsing\QifStatementParser;
use App\Services\Purchases\Extraction\PdfText;
use App\Services\Purchases\Extraction\RuleBasedExtractor;
use App\Services\Shared\Attachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsPurchases;
use Tests\Support\MakesPdfs;
use Tests\TestCase;

/**
 * R-44 security sweep, part 4: uploads and the readers behind them.
 *
 * Type is decided from the bytes (never the name or the declared type); the stored name is server-made; a stored file is
 * reached only through a signed, expiring, ledger-checked route that serves it as a download inside a sandbox; the PDF
 * reader survives malformed and hostile files in bounded time and memory; the bank importers survive garbage.
 */
class UploadsSecurityTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpPurchases();
    }

    private function upload(string $name, string $content, ?string $declared = null)
    {
        $bill = $this->draftBill();

        return $this->inCompany($this->owner, $this->company)->post(route('purchases.bills.attachments.store', $bill), [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function hostileFiles(): array
    {
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

        return [
            'html with a pdf name' => ['invoice.pdf', '<html><script>alert(1)</script></html>'],
            'svg with a png name' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
            'svg with its own name' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
            'php with a pdf name' => ['invoice.pdf', '<?php system($_GET["c"]); ?>'],
            'php, honest name' => ['shell.php', '<?php system($_GET["c"]); ?>'],
            'double extension php' => ['invoice.pdf.php', '<?php system($_GET["c"]); ?>'],
            'executable' => ['setup.exe', "MZ\x90\x00\x03\x00\x00\x00"],
            'shell script' => ['run.sh', "#!/bin/sh\nrm -rf /\n"],
            'empty' => ['empty.pdf', ''],
            'a zip that is not office' => ['sheet.xlsx', "PK\x03\x04".str_repeat("\0", 60)],
            'html named docx' => ['notes.docx', '<!doctype html><title>x</title>'],
            'xml' => ['data.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><x>&a;</x>'],
            'a pdf header followed by html' => ['trick.pdf', "<!doctype html><script>alert(1)</script>\n".$pdf],
        ];
    }

    #[DataProvider('hostileFiles')]
    public function test_a_file_whose_bytes_are_not_an_accepted_type_is_refused_whatever_its_name(string $name, string $content): void
    {
        $before = Attachment::query()->forContext($this->ctx)->count();

        $this->upload($name, $content)->assertSessionHasErrors('file');

        $this->assertSame($before, Attachment::query()->forContext($this->ctx)->count());
        $this->assertSame([], Storage::disk('local')->allFiles('finance-attachments'), 'no byte of it was kept');
    }

    public function test_plain_text_in_any_dress_is_kept_only_as_inert_text(): void
    {
        $this->upload('x.js', 'alert(1)')->assertSessionHasNoErrors();
        $row = Attachment::query()->forContext($this->ctx)->latest('id')->firstOrFail();

        $this->assertSame('text/plain', $row->mime);
        $this->assertStringEndsWith('.txt', $row->path, 'the stored name never keeps the script extension');
        $this->assertFalse($row->isInline(), 'text is never shown inline');
    }

    public function test_a_real_pdf_is_kept_under_a_server_made_name_whatever_the_upload_was_called(): void
    {
        $pdf = MakesPdfs::text(MakesPdfs::invoiceLines());

        foreach (['../../../etc/cron.d/evil.pdf', 'C:\\Windows\\win.ini.pdf', "a\0b.pdf", 'invoice.pdf.php.pdf', '<script>alert(1)</script>.pdf'] as $i => $name) {
            $bill = $this->draftBill(['supplier_reference' => 'T-'.$i]);
            $this->inCompany($this->owner, $this->company)->post(route('purchases.bills.attachments.store', $bill), ['file' => UploadedFile::fake()->createWithContent($name, $pdf.str_repeat(' ', $i + 1))])->assertSessionHasNoErrors();
        }

        $rows = Attachment::query()->forContext($this->ctx)->get();
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('#^finance-attachments/company-\d+/\d{4}-\d{2}/[0-9a-f]{2}/[0-9a-f-]{36}\.pdf$#', $row->path);
            $this->assertStringNotContainsString('..', $row->path);
            $this->assertStringNotContainsString('/', $row->original_name);
            $this->assertStringNotContainsString('\\', $row->original_name);
            $this->assertStringNotContainsString("\0", $row->original_name);
            $this->assertSame('application/pdf', $row->mime);
        }
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        $big = "%PDF-1.4\n".str_repeat('A', 15 * 1024 * 1024 + 10)."\n%%EOF";

        $this->upload('big.pdf', $big)->assertSessionHasErrors('file');
        $this->assertSame(0, Attachment::query()->forContext($this->ctx)->count());
    }

    public function test_a_logo_must_really_be_a_png_or_jpeg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>';
        $this->inCompany($this->owner, $this->company);

        foreach ([['logo.png', $svg], ['logo.png.php', '<?php phpinfo();'], ['logo.jpg', '<html><script>alert(1)</script></html>']] as [$name, $content]) {
            // a real UploadedFile: Laravel's fake() double decides its type from the NAME, a browser upload is decided from the bytes
            $path = tempnam(sys_get_temp_dir(), 'logo');
            file_put_contents($path, $content);
            $response = $this->post(route('sales.profile.logo'), ['logo' => new UploadedFile($path, $name, null, null, true)]);
            $this->assertTrue(session()->has('errors'), "{$name} was accepted as a logo (status ".$response->getStatusCode().')');
        }
        $this->assertSame([], Storage::disk('local')->allFiles('sales-logos'));
    }

    // ── streaming ─────────────────────────────────────────────────────────

    private function storedFile(): Attachment
    {
        $bill = $this->draftBill();
        $this->inCompany($this->owner, $this->company)->post(route('purchases.bills.attachments.store', $bill), ['file' => UploadedFile::fake()->createWithContent('receipt.pdf', MakesPdfs::text(MakesPdfs::invoiceLines()))])->assertSessionHasNoErrors();

        return Attachment::query()->forContext($this->ctx)->latest('id')->firstOrFail();
    }

    public function test_a_stored_file_is_streamed_only_through_a_fresh_untampered_signed_link_to_someone_who_may_see_the_record(): void
    {
        $file = $this->storedFile();
        $service = app(Attachments::class);
        $url = $service->url($file);

        // the owner, with the link: a download in a sandbox
        $response = $this->inCompany($this->owner, $this->company)->get($url)->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // without the signature, or with the id changed after signing
        $this->get(route('attachments.show', $file->id, false))->assertForbidden();
        $this->assertContains($this->get(str_replace('attachments/'.$file->id, 'attachments/'.($file->id + 1), $url))->getStatusCode(), [403, 404]);
        $this->get($url.'&inline=1')->assertForbidden();

        // after it expires
        $this->travel(11)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();

        // a person who is not in the ledger, a stranger signed in elsewhere, and nobody at all
        $this->inCompany($this->user(), $this->company)->get($url)->assertNotFound();
        $this->actingAs($this->user())->get($url)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_a_link_for_one_ledgers_file_does_not_open_in_another_ledger_even_for_a_member_of_both(): void
    {
        $file = $this->storedFile();
        $url = app(Attachments::class)->url($file);

        $other = $this->company($this->owner, 'Second Co');
        $this->inCompany($this->owner, $other)->get($url)->assertNotFound();
    }

    // ── the readers ───────────────────────────────────────────────────────

    public function test_a_compressed_stream_that_would_inflate_to_gigabytes_is_cut_off_in_bounded_time_and_memory(): void
    {
        $context = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $stream = deflate_add($context, 'BT ', ZLIB_NO_FLUSH);
        for ($i = 0; $i < 12; $i++) {
            $stream .= deflate_add($context, str_repeat('BT (x) Tj ', 1_000_000), ZLIB_NO_FLUSH);   // 120 MB of text operators
        }
        $stream .= deflate_add($context, '', ZLIB_FINISH);
        $pdf = "%PDF-1.4\n1 0 obj<</Length ".strlen($stream).">>\nstream\n".$stream."\nendstream\nendobj\n%%EOF";
        $this->assertLessThan(1_500_000, strlen($pdf), 'the bomb itself is small');

        $before = memory_get_peak_usage(true);
        $start = microtime(true);
        $text = (new PdfText)->viaParser($pdf);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed, 'a bomb is cut off, not unpacked');
        $this->assertLessThan(96 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'memory stays bounded');
        $this->assertSame('', $text);
    }

    public function test_a_flood_of_streams_is_bounded_too(): void
    {
        $one = gzcompress(str_repeat("BT (x) Tj ET\n", 2000));
        $pdf = "%PDF-1.4\n";
        for ($i = 0; $i < 6000; $i++) {
            $pdf .= "{$i} 0 obj<</Length ".strlen($one).">>\nstream\n{$one}\nendstream\nendobj\n";
        }
        $pdf .= "%%EOF\n";

        $start = microtime(true);
        (new PdfText)->viaParser($pdf);
        $this->assertLessThan(5.0, microtime(true) - $start);
    }

    public function test_malformed_pdfs_never_crash_the_reader_or_take_long(): void
    {
        mt_srand(20261109);
        $good = MakesPdfs::text(MakesPdfs::invoiceLines(), true);
        $bad = [
            '', '%PDF', '%PDF-1.4', "%PDF-1.4\nstream\n", "%PDF-1.4\nstream\nendstream", "%PDF-1.4\nstream\n\nendstream", "%PDF-1.4\n/Encrypt\nstream\nx\nendstream",
            "%PDF-1.4\nstream\nBT (((((((((((((((((((((((((((((( Tj ET\nendstream", "%PDF-1.4\nstream\nBT [(a)(b)(c Tj ET\nendstream", "%PDF-1.4\nstream\nBT (a\\\nendstream",
            "%PDF-1.4\nstream\nBT ".str_repeat('(', 500000)." ET\nendstream", "%PDF-1.4\nstream\nBT ".str_repeat('[', 500000)." ET\nendstream", "%PDF-1.4\nstream\nBT ".str_repeat('\\', 500000)." ET\nendstream",
            "%PDF-1.4\nstream\nBT ".str_repeat('(a)', 200000)." Tj ET\nendstream", "%PDF-1.4\n".str_repeat("stream\n", 100000), "%PDF-1.4\n".str_repeat("stream\nendstream\n", 50000),
            "%PDF-1.4\nstream\nBT ".str_repeat('T* ', 300000)." ET\nendstream", "%PDF-1.4\nstream\nBT [".str_repeat('-300 (a) ', 100000)."] TJ ET\nendstream", str_repeat("\xff\xfe", 100000),
        ];
        for ($i = 0; $i < 60; $i++) {
            $mutated = $good;
            for ($m = 0; $m < 12; $m++) {
                $pos = mt_rand(0, strlen($mutated) - 1);
                $mutated[$pos] = chr(mt_rand(0, 255));
            }
            $bad[] = $mutated;
            $bad[] = substr($good, 0, mt_rand(8, strlen($good) - 1));
            $bad[] = random_bytes(mt_rand(1, 4000));
        }

        $reader = new PdfText;
        $extractor = new RuleBasedExtractor(new PdfText);
        $slowest = 0.0;
        foreach ($bad as $i => $bytes) {
            $start = microtime(true);
            try {
                $text = $reader->viaParser($bytes);
                $this->assertIsString($text);
                $extractor->parse($text);
            } catch (\Throwable $e) {
                $this->fail("sample {$i} crashed the reader: ".get_class($e).': '.$e->getMessage());
            }
            $slowest = max($slowest, microtime(true) - $start);
        }

        $this->assertLessThan(3.0, $slowest, 'the slowest malformed file took '.round($slowest, 2).'s');
    }

    public function test_the_statement_readers_survive_garbage_in_bounded_time(): void
    {
        mt_srand(20261109);
        $ofx = new OfxStatementParser;
        $qif = new QifStatementParser;
        $csv = new CsvStatementReader;
        $good = "OFXHEADER:100\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><CURDEF>ZAR<BANKTRANLIST><STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261005<TRNAMT>-12.00<FITID>1<NAME>x</STMTTRN></BANKTRANLIST><LEDGERBAL><BALAMT>10.00</LEDGERBAL></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";
        $samples = [
            '', "\0\0\0", '<OFX>', '<OFX>'.str_repeat('<STMTTRN>', 200000), '<OFX>'.str_repeat('<LEDGERBAL>', 200000).'<BALAMT>x', '<OFX><STMTTRN><TRNAMT>'.str_repeat('9', 100000),
            '<OFX><STMTTRN><DTPOSTED>'.str_repeat('1', 100000), "!Type:Bank\n".str_repeat("D01/02/2026\nT-1.00\nP".str_repeat('x', 50)."\n^\n", 50000), "!Type:Bank\nT".str_repeat('9', 200000)."\n^\n", "!Type:Bank\n".str_repeat("^\n", 500000),
            str_repeat('"', 200000), str_repeat("a,b\n", 200000), "date,amount\n".str_repeat('"unterminated', 50000), str_repeat(',', 1_000_000),
        ];
        for ($i = 0; $i < 40; $i++) {
            $m = $good;
            for ($k = 0; $k < 10; $k++) {
                $m[mt_rand(0, strlen($m) - 1)] = chr(mt_rand(0, 255));
            }
            $samples[] = $m;
            $samples[] = random_bytes(mt_rand(1, 3000));
        }

        $slowest = 0.0;
        foreach ($samples as $i => $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'stmt');
            file_put_contents($path, $bytes);
            $start = microtime(true);
            try {
                $ofx->parse($bytes);
                $qif->parse($bytes);
                $csv->read($path);
            } catch (\Throwable $e) {
                $this->fail("sample {$i} crashed a reader: ".get_class($e).': '.$e->getMessage());
            } finally {
                @unlink($path);
            }
            $slowest = max($slowest, microtime(true) - $start);
        }

        $this->assertLessThan(5.0, $slowest, 'the slowest sample took '.round($slowest, 2).'s');
    }
}
