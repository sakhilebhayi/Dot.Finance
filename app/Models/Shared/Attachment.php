<?php

namespace App\Models\Shared;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * The record of one stored file. The file is never replaced: its path, hash, size and name are
 * fixed at creation, and only the extraction draft, the owning record (a file waiting in an
 * inbox may be attached) and the archive flag change. Use the Attachments service to create one.
 */
class Attachment extends Model
{
    use HasLedgerContext;

    public const EXTRACTION_STATUSES = ['none', 'pending', 'draft', 'reviewed', 'rejected', 'failed'];

    private const FROZEN = ['user_id', 'team_id', 'disk', 'path', 'sha256', 'original_name', 'mime', 'size', 'uploaded_by'];

    protected $fillable = [
        'user_id', 'team_id', 'attachable_type', 'attachable_id', 'disk', 'path', 'sha256', 'original_name', 'mime', 'size',
        'uploaded_by', 'extraction_status', 'extraction_engine', 'extraction_json', 'extracted_at',
        'extraction_reviewed_by', 'extraction_reviewed_at', 'archived_at',
    ];

    protected $attributes = ['extraction_status' => 'none'];

    protected $casts = [
        'size' => 'integer',
        'extraction_json' => 'array',
        'extracted_at' => 'datetime',
        'extraction_reviewed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $attachment) {
            foreach (self::FROZEN as $column) {
                if ($attachment->isDirty($column)) {
                    throw new LogicException("An attachment's {$column} never changes: the original file is kept as it was uploaded. Upload a new file instead.");
                }
            }
        });

        static::deleting(fn () => throw new LogicException('Attachments are archived, never deleted: records are kept for the statutory period.'));
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isInline(): bool
    {
        return str_starts_with($this->mime, 'image/') || $this->mime === 'application/pdf';
    }
}
