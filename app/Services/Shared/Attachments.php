<?php

namespace App\Services\Shared;

use App\Models\Shared\Attachment;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\AttachmentRejected;
use App\Support\Shared\Exceptions\DuplicateAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use LogicException;

/**
 * Stores and serves the files attached to records (receipts, invoices, contracts).
 *
 *  - Files go to a private disk, under a path no client controls (ledger / year-month / hash prefix /
 *    random name). A path is never reused and a stored file is never overwritten.
 *  - The sha256 of the content is kept. A file already stored in the SAME ledger is refused as a
 *    duplicate (DuplicateAttachment carries the existing one) unless the caller says duplicates are fine;
 *    re-attaching the same file to the same record is simply returned.
 *  - Size and type are checked against configuration; the type comes from the file's content.
 *  - extraction_json / extraction_status hold a draft read from the document. A draft is never trusted:
 *    it must be reviewed by a person (markReviewed) before anything is created from it.
 *  - A file is reached only through a temporary signed URL to a route that also checks the signed-in
 *    person may view the owning record.
 */
final class Attachments
{
    /**
     * @throws AttachmentRejected
     * @throws DuplicateAttachment
     */
    public function store(UploadedFile $file, ?Model $attachTo = null, ?User $by = null, bool $allowDuplicate = false, ?LedgerContext $context = null): Attachment
    {
        $context ??= LedgerContext::current() ?? throw new AttachmentRejected('No ledger is active.');
        $config = config('shared.attachments');

        if (! $file->isValid()) {
            throw new AttachmentRejected('The upload did not complete. Try again.');
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new AttachmentRejected('The file is empty.');
        }
        if ($size > $config['max_bytes']) {
            throw new AttachmentRejected('The file is larger than '.round($config['max_bytes'] / 1048576, 1).' MB.');
        }

        $mime = $this->detectMime($file);
        $extension = $config['mimes'][$mime] ?? null;
        if ($extension === null) {
            throw new AttachmentRejected('This kind of file is not accepted. Use a PDF, an image (JPG, PNG, WebP, HEIC), a text or CSV file, or an Excel or Word document.');
        }

        if ($attachTo !== null) {
            $this->assertSameLedger($attachTo, $context);
        }

        $hash = hash_file('sha256', $file->getRealPath());

        $existing = Attachment::query()->forContext($context)->where('sha256', $hash)->orderBy('id')->get();

        if ($existing->isNotEmpty()) {
            $same = $attachTo === null ? null : $existing->first(fn (Attachment $a) => $a->attachable_type === $attachTo->getMorphClass() && (int) $a->attachable_id === (int) $attachTo->getKey());

            if ($same !== null) {
                return $same;
            }

            if (! $allowDuplicate) {
                throw new DuplicateAttachment($existing->first());
            }
        }

        $disk = $config['disk'];
        $path = $this->newPath($context, $hash, $extension);

        // Never overwrite: a path that somehow exists is a fault, not a file to replace.
        if (Storage::disk($disk)->exists($path)) {
            throw new LogicException("Refusing to overwrite an existing stored file at {$path}.");
        }

        $stream = fopen($file->getRealPath(), 'rb');
        try {
            Storage::disk($disk)->put($path, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        try {
            return DB::transaction(fn () => Attachment::query()->create($context->ownerAttributes() + [
                'attachable_type' => $attachTo?->getMorphClass(),
                'attachable_id' => $attachTo?->getKey(),
                'disk' => $disk,
                'path' => $path,
                'sha256' => $hash,
                'original_name' => $this->cleanName($file->getClientOriginalName()),
                'mime' => $mime,
                'size' => $size,
                'uploaded_by' => $by?->id,
            ]));
        } catch (\Throwable $e) {
            // the row did not land: do not leave an orphan file behind
            Storage::disk($disk)->delete($path);
            throw $e;
        }
    }

    /** An already-stored file in this ledger with the same content, if any. */
    public function findDuplicate(string $sha256, ?LedgerContext $context = null): ?Attachment
    {
        $context ??= LedgerContext::current();

        return $context ? Attachment::query()->forContext($context)->where('sha256', $sha256)->orderBy('id')->first() : null;
    }

    /** Attach a file that was waiting in the inbox to a record. A file already attached elsewhere is not moved. */
    public function attach(Attachment $attachment, Model $to): Attachment
    {
        if ($attachment->attachable_type !== null) {
            throw new LogicException('This file is already attached to a record.');
        }

        $this->assertSameLedger($to, $attachment->ledgerContext());

        $attachment->forceFill(['attachable_type' => $to->getMorphClass(), 'attachable_id' => $to->getKey()])->save();

        return $attachment;
    }

    /** Take a file out of use without deleting it (records are kept for the statutory period). */
    public function archive(Attachment $attachment): Attachment
    {
        $attachment->forceFill(['archived_at' => now()])->save();

        return $attachment;
    }

    /** A temporary signed URL to the streaming route. The route still checks the person may view the record. */
    public function url(Attachment $attachment, bool $inline = false, ?int $minutes = null): string
    {
        return URL::temporarySignedRoute(
            'attachments.show',
            now()->addMinutes($minutes ?? (int) config('shared.attachments.url_ttl_minutes')),
            ['attachment' => $attachment->id] + ($inline && $attachment->isInline() ? ['inline' => 1] : []),
        );
    }

    /** True when the stored bytes still hash to the recorded sha256. */
    public function verify(Attachment $attachment): bool
    {
        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            return false;
        }

        $stream = $disk->readStream($attachment->path);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        is_resource($stream) && fclose($stream);

        return hash_equals($attachment->sha256, hash_final($context));
    }

    // ── extraction drafts ───────────────────────────────────────────────

    /** Record a draft read from the document. It is a suggestion until a person reviews it. */
    public function saveExtractionDraft(Attachment $attachment, array $draft, string $engine): Attachment
    {
        $attachment->forceFill([
            'extraction_status' => 'draft',
            'extraction_engine' => $engine,
            'extraction_json' => $draft,
            'extracted_at' => now(),
            'extraction_reviewed_by' => null,
            'extraction_reviewed_at' => null,
        ])->save();

        return $attachment;
    }

    public function markExtractionPending(Attachment $attachment): Attachment
    {
        $attachment->forceFill(['extraction_status' => 'pending'])->save();

        return $attachment;
    }

    public function markExtractionFailed(Attachment $attachment, string $reason): Attachment
    {
        $attachment->forceFill(['extraction_status' => 'failed', 'extraction_json' => ['error' => mb_substr($reason, 0, 500)], 'extracted_at' => now()])->save();

        return $attachment;
    }

    /**
     * A person has checked the draft. $corrected, when given, replaces the draft with what they confirmed.
     * Only a draft can be reviewed.
     */
    public function markReviewed(Attachment $attachment, User $by, ?array $corrected = null): Attachment
    {
        if ($attachment->extraction_status !== 'draft') {
            throw new LogicException('Only an extraction draft can be reviewed.');
        }

        $attachment->forceFill([
            'extraction_status' => 'reviewed',
            'extraction_json' => $corrected ?? $attachment->extraction_json,
            'extraction_reviewed_by' => $by->id,
            'extraction_reviewed_at' => now(),
        ])->save();

        return $attachment;
    }

    public function rejectExtraction(Attachment $attachment, User $by): Attachment
    {
        if ($attachment->extraction_status !== 'draft') {
            throw new LogicException('Only an extraction draft can be rejected.');
        }

        $attachment->forceFill(['extraction_status' => 'rejected', 'extraction_reviewed_by' => $by->id, 'extraction_reviewed_at' => now()])->save();

        return $attachment;
    }

    // ── internals ───────────────────────────────────────────────────────

    private function detectMime(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file->getRealPath()) ?: 'application/octet-stream';

        // A CSV or plain-text file is detected as text/plain: tell them apart by the name only when the content is text.
        if ($mime === 'text/plain' && strtolower($file->getClientOriginalExtension()) === 'csv') {
            return 'text/csv';
        }

        // OOXML containers are detected as zip by some libmagic versions: trust the name only for the two office types.
        if (in_array($mime, ['application/zip', 'application/octet-stream'], true)) {
            return match (strtolower($file->getClientOriginalExtension())) {
                'xlsx' => $this->looksLikeOoxml($file, 'xl/') ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : $mime,
                'docx' => $this->looksLikeOoxml($file, 'word/') ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : $mime,
                default => $mime,
            };
        }

        return $mime;
    }

    private function looksLikeOoxml(UploadedFile $file, string $folder): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return false;
        }

        $found = $zip->locateName('[Content_Types].xml') !== false && $this->zipHasFolder($zip, $folder);
        $zip->close();

        return $found;
    }

    private function zipHasFolder(\ZipArchive $zip, string $folder): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), $folder)) {
                return true;
            }
        }

        return false;
    }

    private function newPath(LedgerContext $context, string $hash, string $extension): string
    {
        $ledger = $context->isCompany() ? 'company-'.$context->teamId : 'personal-'.$context->userId;

        return 'finance-attachments/'.$ledger.'/'.now()->format('Y-m').'/'.substr($hash, 0, 2).'/'.Str::uuid().'.'.$extension;
    }

    /** A display name only: no path parts, no control characters. */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return mb_substr($name !== '' ? $name : 'file', 0, 255);
    }

    private function assertSameLedger(Model $record, LedgerContext $context): void
    {
        if (! method_exists($record, 'ledgerContext') || ! $record->ledgerContext()->equals($context)) {
            throw new AttachmentRejected('A file can only be attached to a record in the same ledger.');
        }
    }
}
