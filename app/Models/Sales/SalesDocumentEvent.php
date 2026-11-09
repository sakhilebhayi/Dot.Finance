<?php

namespace App\Models\Sales;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One line of a document's trail: created, issued, emailed, viewed, paid, voided. Append-only. */
class SalesDocumentEvent extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The document trail is append-only.'));
        static::deleting(fn () => throw new LogicException('The document trail is append-only.'));
    }

    public function ledgerReferences(): array
    {
        return ['document_id' => SalesDocument::class];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'document_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
