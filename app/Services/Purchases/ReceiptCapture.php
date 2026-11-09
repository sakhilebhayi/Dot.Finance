<?php

namespace App\Services\Purchases;

use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Purchases\ExpenseClaimLine;
use App\Models\Shared\Attachment;
use App\Models\User;
use App\Services\Purchases\Extraction\ExtractionResult;
use App\Services\Purchases\Extraction\ExtractorManager;
use App\Services\Shared\Attachments;
use App\Support\Ledger\LedgerContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Receipt and supplier-invoice capture. A file is uploaded to the ledger's inbox, read into a DRAFT, and kept as evidence whatever
 * happens next. Reading never creates a bill: a person looks at the draft beside the document, corrects it, and then chooses to
 * create a bill (still a draft) or add it to an expense claim.
 *
 * The original file and every reading are preserved. attachments.extraction_json holds:
 *   versions   every reading, oldest first: engine, time, fields, confidence per field, warnings, whether manual entry is needed
 *   corrected  what the person confirmed (fields, who, when), once they have
 *   outcome    what was made from it ({type: bill|claim_line, id})
 */
final class ReceiptCapture
{
    public function __construct(
        private readonly Attachments $attachments,
        private readonly ExtractorManager $extractors,
        private readonly BillWorkflow $bills,
        private readonly ClaimWorkflow $claims,
    ) {}

    /**
     * Store the file in the inbox and read it. A file already in this ledger (same content) is refused with DuplicateAttachment.
     *
     * @throws \App\Support\Shared\Exceptions\DuplicateAttachment
     * @throws \App\Support\Shared\Exceptions\AttachmentRejected
     */
    public function capture(LedgerContext $context, User $by, UploadedFile $file): Attachment
    {
        return LedgerContext::run($context, function () use ($context, $by, $file) {
            $attachment = $this->attachments->store($file, null, $by, false, $context);

            return $this->read($attachment);
        });
    }

    /** Read (or read again with whatever engines are on) and add the result as a new version. The earlier readings stay. */
    public function read(Attachment $attachment): Attachment
    {
        return LedgerContext::run($attachment->ledgerContext(), function () use ($attachment) {
            $record = $this->record($attachment);

            foreach ($this->extractors->run($attachment) as $result) {
                $record['versions'][] = $result->toVersion(count($record['versions']) + 1);
            }

            $record['corrected'] = null;
            $last = end($record['versions']);
            $engine = $last['engine'] ?? 'manual';

            return $this->attachments->saveExtractionDraft($attachment, $record, $engine);
        });
    }

    /** @return array{versions: list<array<string, mixed>>, corrected: ?array<string, mixed>, outcome: ?array<string, mixed>} */
    public function record(Attachment $attachment): array
    {
        $json = (array) $attachment->extraction_json;

        return [
            'versions' => array_values((array) ($json['versions'] ?? [])),
            'corrected' => $json['corrected'] ?? null,
            'outcome' => $json['outcome'] ?? null,
        ];
    }

    /** The newest reading, or null if there is none. @return array<string, mixed>|null */
    public function latest(Attachment $attachment): ?array
    {
        $versions = $this->record($attachment)['versions'];

        return $versions === [] ? null : $versions[count($versions) - 1];
    }

    /**
     * Make a DRAFT bill from the reviewed document, attach the file to it and mark the reading reviewed. One transaction.
     *
     * @param  array<string, mixed>  $fields  what the person confirmed: party_id, reference, bill_date, due_date, currency, document_type, supplier_vat_number, total_cents (gross), tax_cents (as read, for the check), gl_account_id, tax_code, description
     */
    public function toBill(LedgerContext $context, User $by, Attachment $attachment, array $fields): Bill
    {
        return LedgerContext::run($context, fn () => DB::transaction(function () use ($context, $by, $attachment, $fields) {
            $this->assertOpen($attachment);

            $bill = $this->bills->saveDraft($context, $by, [
                'party_id' => $fields['party_id'] ?? null, 'bill_date' => $fields['bill_date'] ?? now()->toDateString(), 'due_date' => $fields['due_date'] ?? null,
                'currency' => $fields['currency'] ?? null, 'tax_inclusive' => true, 'supplier_reference' => $fields['reference'] ?? null,
                'document_type' => $fields['document_type'] ?? 'unknown', 'supplier_vat_number' => $fields['supplier_vat_number'] ?? null, 'source' => 'capture',
                'lines' => [[
                    'description' => $fields['description'] ?? 'As per the attached document', 'gl_account_id' => $fields['gl_account_id'] ?? 0, 'quantity' => '1',
                    'unit_price_cents' => (int) ($fields['total_cents'] ?? 0), 'tax_code' => $fields['tax_code'] ?? '',
                ]],
            ]);

            if (isset($fields['tax_cents']) && (int) $fields['tax_cents'] !== $bill->tax_cents) {
                $bill->forceFill(['notes' => trim(($bill->notes ? $bill->notes."\n" : '').'The document shows VAT of '.number_format(((int) $fields['tax_cents']) / 100, 2).'; the VAT worked out from the tax code is '.number_format($bill->tax_cents / 100, 2).'. Check which is right.')])->save();
            }

            $this->attachments->attach($attachment, $bill);
            $this->review($attachment, $by, $fields, ['type' => 'bill', 'id' => $bill->id]);

            return $bill;
        }));
    }

    /** Add the reviewed receipt to a DRAFT expense claim as a new line and attach the file to that line. */
    public function toClaimLine(LedgerContext $context, User $by, Attachment $attachment, ExpenseClaim $claim, array $fields): ExpenseClaimLine
    {
        return LedgerContext::run($context, fn () => DB::transaction(function () use ($context, $by, $attachment, $claim, $fields) {
            $this->assertOpen($attachment);

            $claim = $this->claims->addReceipt($context, $by, $claim, [
                'spent_on' => $fields['bill_date'] ?? now()->toDateString(), 'merchant' => $fields['supplier_name'] ?? 'Unknown merchant', 'description' => $fields['description'] ?? 'As per the attached receipt',
                'receipt_type' => $fields['document_type'] ?? 'unknown', 'merchant_vat_number' => $fields['supplier_vat_number'] ?? null, 'receipt_reference' => $fields['reference'] ?? null,
                'gl_account_id' => $fields['gl_account_id'] ?? 0, 'tax_code' => $fields['tax_code'] ?? '', 'gross_cents' => (int) ($fields['total_cents'] ?? 0),
            ]);

            $line = $claim->lines()->orderByDesc('line_no')->firstOrFail();
            $this->attachments->attach($attachment, $line);
            $this->review($attachment, $by, $fields, ['type' => 'claim_line', 'id' => $line->id]);

            return $line;
        }));
    }

    /** The reading is wrong or the file is not a purchase document. The file stays on record. */
    public function reject(User $by, Attachment $attachment): Attachment
    {
        return LedgerContext::run($attachment->ledgerContext(), function () use ($by, $attachment) {
            $this->ensureDraft($attachment);

            return $this->attachments->rejectExtraction($attachment->refresh(), $by);
        });
    }

    private function review(Attachment $attachment, User $by, array $fields, array $outcome): void
    {
        $this->ensureDraft($attachment);
        $attachment->refresh();

        $record = $this->record($attachment);
        $record['corrected'] = ['fields' => $fields, 'by' => $by->id, 'at' => now()->toIso8601String()];
        $record['outcome'] = $outcome;

        $this->attachments->markReviewed($attachment, $by, $record);
    }

    /** A manual-entry file (no reading, or a failed one) is brought to "draft" so it can be reviewed like any other. */
    private function ensureDraft(Attachment $attachment): void
    {
        if ($attachment->extraction_status === 'draft') {
            return;
        }

        $record = $this->record($attachment);
        if ($record['versions'] === []) {
            $record['versions'][] = (new ExtractionResult('manual', [], [], ['Entered by hand.'], true))->toVersion(1);
        }

        $this->attachments->saveExtractionDraft($attachment, $record, 'manual');
    }

    private function assertOpen(Attachment $attachment): void
    {
        if ($attachment->attachable_type !== null) {
            throw new PurchasesException('This file is already attached to a record.', 'already_attached');
        }
        if ($attachment->extraction_status === 'reviewed') {
            throw new PurchasesException('This document has already been turned into a record.', 'already_reviewed');
        }
    }
}
