<?php

namespace Tests\Feature\Purchases;

use App\Models\Accounting\JournalEntry;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Shared\Attachment;
use App\Services\Purchases\ClaimWorkflow;
use App\Services\Purchases\Extraction\ModelExtractor;
use App\Services\Purchases\Extraction\PdfText;
use App\Services\Purchases\Extraction\RuleBasedExtractor;
use App\Services\Purchases\PurchasesException;
use App\Services\Purchases\ReceiptCapture;
use App\Services\Shared\Attachments;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\DuplicateAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsPurchases;
use Tests\Support\MakesPdfs;
use Tests\TestCase;

class ReceiptCaptureTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpPurchases();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function upload(string $contents, string $name = 'invoice.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function capture(?string $pdf = null, string $name = 'invoice.pdf'): Attachment
    {
        return app(ReceiptCapture::class)->capture($this->ctx, $this->owner, $this->upload($pdf ?? MakesPdfs::text(MakesPdfs::invoiceLines()), $name));
    }

    // ── the reader ──────────────────────────────────────────────────────

    public function test_the_text_of_a_plain_and_of_a_compressed_pdf_is_read_without_any_pdf_library(): void
    {
        foreach ([false, true] as $compressed) {
            $text = app(PdfText::class)->viaParser(MakesPdfs::text(MakesPdfs::invoiceLines(), $compressed));

            $this->assertStringContainsString('TAX INVOICE', $text);
            $this->assertStringContainsString('VAT Reg No: 4123456789', $text);
            $this->assertStringContainsString('Total (incl. VAT) R 1 150.00', $text);
        }
    }

    public function test_the_rule_based_reader_finds_the_fields_and_is_sure_only_when_the_figures_agree(): void
    {
        $result = app(RuleBasedExtractor::class)->parse(implode("\n", MakesPdfs::invoiceLines()));

        $this->assertSame('Acme Supplies (Pty) Ltd', $result->fields['supplier_name']);
        $this->assertSame('4123456789', $result->fields['supplier_vat_number']);
        $this->assertSame('INV-20457', $result->fields['reference']);
        $this->assertSame('2026-11-05', $result->fields['bill_date']);
        $this->assertSame('2026-12-05', $result->fields['due_date']);
        $this->assertSame('tax_invoice', $result->fields['document_type']);
        $this->assertSame([100_000, 15_000, 115_000], [$result->fields['subtotal_cents'], $result->fields['tax_cents'], $result->fields['total_cents']]);
        $this->assertSame('ZAR', $result->fields['currency']);
        $this->assertSame('high', $result->confidence['total_cents']);
        $this->assertSame('low', $result->confidence['supplier_name'], 'a guess is labelled a guess');
    }

    public function test_figures_that_do_not_add_up_are_flagged_and_not_trusted(): void
    {
        $result = app(RuleBasedExtractor::class)->parse(implode("\n", MakesPdfs::invoiceLines(total: '1 250.00')));

        $this->assertSame('low', $result->confidence['total_cents']);
        $this->assertStringContainsString('do not add up', implode(' ', $result->warnings));
    }

    public function test_amounts_are_parsed_in_both_south_african_and_english_notation(): void
    {
        foreach (['1 234.56' => 123_456, '1,234.56' => 123_456, '1.234,56' => 123_456, '1234,56' => 123_456, '12.5' => 1_250, '0.99' => 99] as $raw => $cents) {
            $this->assertSame($cents, RuleBasedExtractor::parseAmount($raw), $raw);
        }
        $this->assertNull(RuleBasedExtractor::parseAmount('1234'), 'a bare whole number is not money');
        $this->assertNull(RuleBasedExtractor::parseAmount('12,34,56'));
    }

    public function test_dates_are_day_first_and_impossible_ones_are_rejected(): void
    {
        $this->assertSame('2026-11-05', RuleBasedExtractor::normaliseDate('05/11/2026'));
        $this->assertSame('2026-11-05', RuleBasedExtractor::normaliseDate('5 November 2026'));
        $this->assertNull(RuleBasedExtractor::normaliseDate('31/02/2026'), '31 February does not exist');
        $this->assertNull(RuleBasedExtractor::normaliseDate('not a date'));
    }

    // ── capture ─────────────────────────────────────────────────────────

    public function test_an_upload_is_stored_read_into_a_draft_and_creates_nothing(): void
    {
        $attachment = $this->capture();

        $this->assertNull($attachment->attachable_type, 'it waits in the inbox');
        $this->assertSame('draft', $attachment->extraction_status);
        $this->assertSame('rules', $attachment->extraction_engine);
        $this->assertSame(115_000, $attachment->extraction_json['versions'][0]['fields']['total_cents']);

        // reading never creates a bill, a claim or a journal entry
        $this->assertSame(0, Bill::query()->forContext($this->ctx)->count());
        $this->assertSame(0, ExpenseClaim::query()->forContext($this->ctx)->count());
        $this->assertSame(0, JournalEntry::query()->forContext($this->ctx)->where('source_type', 'bill')->count());
        $this->assertSame(0, $this->apBalance());
    }

    public function test_an_image_with_no_model_enabled_is_stored_and_asks_for_manual_entry(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $attachment = $this->capture($png, 'photo.png');

        $this->assertSame('png', pathinfo($attachment->path, PATHINFO_EXTENSION));
        $latest = app(ReceiptCapture::class)->latest($attachment);
        $this->assertTrue($latest['needs_manual_entry']);
        $this->assertSame([], $latest['fields']);
        $this->assertSame('manual', $latest['engine']);
    }

    public function test_the_same_file_twice_is_refused_and_names_the_first(): void
    {
        $first = $this->capture();

        try {
            $this->capture();
            $this->fail('duplicate file');
        } catch (DuplicateAttachment $e) {
            $this->assertSame($first->id, $e->existing->id);
        }
        $this->assertSame(1, Attachment::query()->forContext($this->ctx)->count());
    }

    public function test_reading_again_adds_a_version_and_keeps_the_original_file_untouched(): void
    {
        $attachment = $this->capture();
        $hash = $attachment->sha256;

        app(ReceiptCapture::class)->read($attachment);

        $versions = app(ReceiptCapture::class)->record($attachment->fresh())['versions'];
        $this->assertCount(2, $versions);
        $this->assertSame([1, 2], array_column($versions, 'n'));
        $this->assertSame($hash, $attachment->fresh()->sha256);
        $this->assertTrue(app(Attachments::class)->verify($attachment->fresh()), 'the stored bytes still hash to the recorded value');
    }

    public function test_after_review_a_draft_bill_is_made_with_the_file_attached_and_the_history_kept(): void
    {
        $attachment = $this->capture();

        $bill = app(ReceiptCapture::class)->toBill($this->ctx, $this->owner, $attachment, [
            'party_id' => $this->supplier->id, 'reference' => 'INV-20457', 'bill_date' => '2026-11-05', 'due_date' => '2026-12-05', 'currency' => 'ZAR', 'document_type' => 'tax_invoice',
            'supplier_vat_number' => '4123456789', 'total_cents' => 115_000, 'tax_cents' => 15_000, 'gl_account_id' => $this->gl('6190')->id, 'tax_code' => 'ZA_VAT_STD', 'description' => 'Coffee bags',
        ]);

        $this->assertSame('draft', $bill->status, 'a captured bill is still a draft');
        $this->assertSame('capture', $bill->source);
        $this->assertSame([100_000, 15_000, 115_000], [$bill->subtotal_cents, $bill->tax_cents, $bill->total_cents]);
        $this->assertSame(0, JournalEntry::query()->forContext($this->ctx)->where('source_type', 'bill')->count());

        $attachment = $attachment->fresh();
        $this->assertSame('bill', $attachment->attachable_type);
        $this->assertSame($bill->id, (int) $attachment->attachable_id);
        $this->assertSame('reviewed', $attachment->extraction_status);
        $this->assertCount(1, $attachment->extraction_json['versions'], 'what was read is kept');
        $this->assertSame(115_000, $attachment->extraction_json['corrected']['fields']['total_cents'], 'and what the person confirmed');
        $this->assertSame(['type' => 'bill', 'id' => $bill->id], $attachment->extraction_json['outcome']);
        $this->assertSame($this->owner->id, $attachment->extraction_reviewed_by);
    }

    public function test_a_reviewed_document_cannot_be_turned_into_a_second_bill(): void
    {
        $attachment = $this->capture();
        $fields = ['party_id' => $this->supplier->id, 'bill_date' => '2026-11-05', 'document_type' => 'tax_invoice', 'total_cents' => 115_000, 'gl_account_id' => $this->gl('6190')->id, 'tax_code' => 'ZA_VAT_STD'];
        app(ReceiptCapture::class)->toBill($this->ctx, $this->owner, $attachment, $fields);

        $this->expectException(PurchasesException::class);
        app(ReceiptCapture::class)->toBill($this->ctx, $this->owner, $attachment->fresh(), $fields);
    }

    public function test_a_wrong_vat_amount_on_the_document_is_noted_on_the_bill(): void
    {
        $attachment = $this->capture();

        $bill = app(ReceiptCapture::class)->toBill($this->ctx, $this->owner, $attachment, [
            'party_id' => $this->supplier->id, 'bill_date' => '2026-11-05', 'document_type' => 'tax_invoice', 'total_cents' => 115_000, 'tax_cents' => 20_000,
            'gl_account_id' => $this->gl('6190')->id, 'tax_code' => 'ZA_VAT_STD',
        ]);

        $this->assertStringContainsString('document shows VAT of 200.00', $bill->notes);
    }

    public function test_a_captured_receipt_can_be_added_to_a_draft_claim_as_a_line(): void
    {
        $attachment = $this->capture();
        $claim = app(ClaimWorkflow::class)->saveDraft($this->ctx, $this->owner, [
            'title' => 'Trip', 'claim_date' => '2026-11-04',
            'lines' => [['spent_on' => '2026-11-04', 'merchant' => 'Shell', 'description' => 'Fuel', 'receipt_type' => 'tax_invoice', 'merchant_vat_number' => '4111111111', 'gl_account_id' => $this->gl('6170')->id, 'tax_code' => 'ZA_VAT_STD', 'gross_cents' => 23_000]],
        ]);

        $line = app(ReceiptCapture::class)->toClaimLine($this->ctx, $this->owner, $attachment, $claim, [
            'bill_date' => '2026-11-05', 'supplier_name' => 'Acme Supplies', 'document_type' => 'tax_invoice', 'supplier_vat_number' => '4123456789', 'total_cents' => 115_000,
            'gl_account_id' => $this->gl('6190')->id, 'tax_code' => 'ZA_VAT_STD', 'description' => 'Coffee bags',
        ]);

        $claim = $claim->fresh();
        $this->assertSame(2, LedgerContext::run($this->ctx, fn () => $claim->lines()->count()));
        $this->assertSame(138_000, $claim->total_cents);
        $this->assertSame('expense_claim_line', $attachment->fresh()->attachable_type);
        $this->assertSame($line->id, (int) $attachment->fresh()->attachable_id);
    }

    public function test_correcting_a_claim_keeps_the_file_on_its_receipt(): void
    {
        $attachment = $this->capture();
        $claim = app(ClaimWorkflow::class)->saveDraft($this->ctx, $this->owner, [
            'title' => 'Trip', 'claim_date' => '2026-11-04',
            'lines' => [['spent_on' => '2026-11-04', 'merchant' => 'Shell', 'description' => 'Fuel', 'receipt_type' => 'none', 'gl_account_id' => $this->gl('6170')->id, 'tax_code' => 'ZA_VAT_ZERO', 'gross_cents' => 23_000]],
        ]);
        $line = LedgerContext::run($this->ctx, fn () => $claim->lines()->first());
        app(Attachments::class)->attach($attachment, $line);

        app(ClaimWorkflow::class)->saveDraft($this->ctx, $this->owner, [
            'title' => 'Trip (fixed)', 'claim_date' => '2026-11-04',
            'lines' => [['spent_on' => '2026-11-04', 'merchant' => 'Shell Rondebosch', 'description' => 'Fuel', 'receipt_type' => 'none', 'gl_account_id' => $this->gl('6170')->id, 'tax_code' => 'ZA_VAT_ZERO', 'gross_cents' => 24_000]],
        ], $claim);

        $this->assertSame($line->id, (int) $attachment->fresh()->attachable_id, 'the receipt stays with its line');
    }

    public function test_a_document_can_be_rejected_and_stays_on_file(): void
    {
        $attachment = $this->capture();

        app(ReceiptCapture::class)->reject($this->owner, $attachment);

        $this->assertSame('rejected', $attachment->fresh()->extraction_status);
        $this->assertTrue(app(Attachments::class)->verify($attachment->fresh()));
    }

    // ── the model-backed reader ─────────────────────────────────────────

    public function test_the_model_reader_is_off_by_default(): void
    {
        $this->assertFalse(app(ModelExtractor::class)->enabled());
        Http::fake();
        $this->capture(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), 'photo.png');
        Http::assertNothingSent();
    }

    public function test_the_model_answer_is_validated_field_by_field_and_never_trusted_beyond_medium(): void
    {
        $result = app(ModelExtractor::class)->validated('Here you go: {"supplier_name":"Acme","supplier_vat_number":"4123456789","reference":"INV-1","bill_date":"2026-11-05","due_date":"2026-02-31","currency":"ZAR","subtotal_cents":100000,"tax_cents":"15000","total_cents":115000,"document_type":"tax_invoice","evil":"<script>"}');

        $this->assertSame('Acme', $result->fields['supplier_name']);
        $this->assertSame(115_000, $result->fields['total_cents']);
        $this->assertArrayNotHasKey('due_date', $result->fields, 'a date that is not a real date is dropped');
        $this->assertArrayNotHasKey('tax_cents', $result->fields, 'an amount that is not a whole number is dropped');
        $this->assertArrayNotHasKey('evil', $result->fields);
        $this->assertSame(['medium'], array_values(array_unique($result->confidence)));
        $this->assertStringContainsString('language model', $result->warnings[0]);
    }

    public function test_an_answer_that_is_not_json_is_a_failure_not_a_guess(): void
    {
        $this->expectException(RuntimeException::class);
        app(ModelExtractor::class)->validated('I could not read that, sorry.');
    }

    public function test_when_switched_on_the_model_reads_an_image_and_the_result_is_still_only_a_draft(): void
    {
        config(['purchases.extraction.model_enabled' => true, 'purchases.extraction.model_api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => '{"supplier_name":"Spar","total_cents":4599,"currency":"ZAR","document_type":"abridged_invoice","reference":null,"bill_date":"2026-11-06"}']]])]);

        $attachment = $this->capture(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), 'photo.png');

        $this->assertSame('model', $attachment->extraction_engine);
        $this->assertSame(4_599, app(ReceiptCapture::class)->latest($attachment)['fields']['total_cents']);
        $this->assertSame('draft', $attachment->extraction_status);
        $this->assertSame(0, Bill::query()->forContext($this->ctx)->count());
        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'test-key'));
    }
}
