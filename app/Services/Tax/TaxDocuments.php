<?php

namespace App\Services\Tax;

use App\Models\Shared\Attachment;
use App\Models\Tax\TaxCorrespondence;
use App\Models\Tax\TaxDocument;
use App\Models\User;
use App\Services\Shared\Attachments;
use App\Services\Tax\Exceptions\TaxException;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The tax document vault and the correspondence log. A vault entry names what a document is, which stored file holds it (the
 * Attachments service keeps the original, hashed and never overwritten) and the day it may be discarded. Nothing is deleted: an entry
 * is archived, and only after its retention date and with no legal hold.
 *
 * Retention is counted from the LATER of the document date and the day it was filed here, which can only be later than the law's
 * start (the TAA counts from the submission of the return), so a record is never offered for discarding early. Company records
 * (registration, contracts) use the 7-year Companies Act figure (medium confidence in the research).
 */
final class TaxDocuments
{
    public function __construct(private readonly TaxRules $rules, private readonly Attachments $attachments) {}

    /**
     * @param  array{category: string, title: string, period_label?: ?string, document_date?: ?string, tax_deadline_id?: ?int, notes?: ?string, legal_hold?: bool}  $data
     */
    public function add(LedgerContext $ctx, array $data, ?UploadedFile $file, User $by, ?CarbonImmutable $today = null): TaxDocument
    {
        $today ??= CarbonImmutable::today();
        if (! array_key_exists($data['category'], TaxDocument::CATEGORIES)) {
            throw new TaxException('Unknown document category.');
        }

        $docDate = isset($data['document_date']) && $data['document_date'] ? CarbonImmutable::parse($data['document_date']) : null;
        $from = $docDate !== null && $docDate->gt($today) ? $docDate : $today;
        $isCompany = in_array($data['category'], ['registration', 'contract'], true);
        $session = $this->rules->session($ctx);
        $years = $session->rule($isCompany ? 'records.retention_years.company' : 'records.retention_years.tax', $today)->integer();
        $basis = $isCompany
            ? "{$years} years (Companies Act section 24; secondary sources, medium confidence), counted from the later of the document date and the day it was filed here."
            : "{$years} years (Tax Administration Act section 29; VAT Act), counted from the later of the document date and the day it was filed here.";

        return DB::transaction(function () use ($ctx, $data, $file, $by, $docDate, $from, $years, $basis) {
            $attachment = $file !== null ? $this->attachments->store($file, null, $by, true, $ctx) : null;

            $doc = TaxDocument::query()->create($ctx->ownerAttributes() + [
                'category' => $data['category'], 'title' => mb_substr($data['title'], 0, 160), 'period_label' => $data['period_label'] ?? null,
                'tax_deadline_id' => $data['tax_deadline_id'] ?? null, 'attachment_id' => $attachment?->id, 'document_date' => $docDate?->toDateString(),
                'retention_until' => $from->addYears($years)->toDateString(), 'legal_hold' => (bool) ($data['legal_hold'] ?? false),
                'retention_basis' => $basis, 'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 500) : null, 'added_by_user_id' => $by->id,
            ]);

            if ($attachment !== null) {
                $this->attachments->attach($attachment, $doc);
            }

            FinanceAudit::record($ctx->ownerAttributes(), 'created', 'tax_document', (int) $doc->id, 'Tax document: '.$doc->categoryLabel(), ['category' => ['after' => $doc->category], 'retention_until' => ['after' => $doc->retention_until->toDateString()]], null, null, $by->id);

            return $doc;
        });
    }

    public function setLegalHold(LedgerContext $ctx, TaxDocument $doc, bool $hold, User $by): TaxDocument
    {
        $doc->forceFill(['legal_hold' => $hold])->save();
        FinanceAudit::record($ctx->ownerAttributes(), $hold ? 'legal_hold_set' : 'legal_hold_cleared', 'tax_document', (int) $doc->id, 'Tax document: '.$doc->categoryLabel(), ['legal_hold' => ['after' => $hold]], null, null, $by->id);

        return $doc;
    }

    public function archive(LedgerContext $ctx, TaxDocument $doc, User $by, ?CarbonImmutable $today = null): TaxDocument
    {
        $today ??= CarbonImmutable::today();
        if ($doc->legal_hold) {
            throw new TaxException('This record is on legal hold and cannot be archived.');
        }
        if ($doc->retention_until->gte($today)) {
            throw new TaxException('This record must be kept until '.$doc->retention_until->format('j F Y').'.');
        }
        $doc->forceFill(['archived_at' => now()])->save();
        FinanceAudit::record($ctx->ownerAttributes(), 'archived', 'tax_document', (int) $doc->id, 'Tax document: '.$doc->categoryLabel(), [], 'after the retention date', null, $by->id);

        return $doc;
    }

    /** @param  array{direction: string, channel: string, reference?: ?string, subject: string, summary?: ?string, occurred_on: string, response_due_on?: ?string}  $data */
    public function logCorrespondence(LedgerContext $ctx, array $data, ?UploadedFile $file, User $by): TaxCorrespondence
    {
        return DB::transaction(function () use ($ctx, $data, $file, $by) {
            $attachment = $file !== null ? $this->attachments->store($file, null, $by, true, $ctx) : null;
            $row = TaxCorrespondence::query()->create($ctx->ownerAttributes() + [
                'direction' => $data['direction'], 'channel' => $data['channel'], 'reference' => $data['reference'] ?? null, 'subject' => mb_substr($data['subject'], 0, 200),
                'summary' => $data['summary'] ?? null, 'occurred_on' => $data['occurred_on'], 'response_due_on' => $data['response_due_on'] ?? null,
                'status' => ($data['direction'] === 'received' && ! empty($data['response_due_on'])) ? 'open' : 'closed', 'attachment_id' => $attachment?->id, 'created_by_user_id' => $by->id,
            ]);
            if ($attachment !== null) {
                $this->attachments->attach($attachment, $row);
            }
            FinanceAudit::record($ctx->ownerAttributes(), 'created', 'tax_correspondence', (int) $row->id, 'SARS correspondence ('.$row->direction.')', ['direction' => ['after' => $row->direction]], null, null, $by->id);

            return $row;
        });
    }

    public function setCorrespondenceStatus(LedgerContext $ctx, TaxCorrespondence $row, string $status, User $by): TaxCorrespondence
    {
        if (! in_array($status, ['open', 'responded', 'closed'], true)) {
            throw new TaxException('Unknown status.');
        }
        $row->forceFill(['status' => $status])->save();
        FinanceAudit::record($ctx->ownerAttributes(), 'status_changed', 'tax_correspondence', (int) $row->id, 'SARS correspondence', ['status' => ['after' => $status]], null, null, $by->id);

        return $row;
    }

    public function attachmentFor(LedgerContext $ctx, TaxDocument $doc): ?Attachment
    {
        return $doc->attachment_id ? Attachment::query()->forContext($ctx)->find($doc->attachment_id) : null;
    }
}
