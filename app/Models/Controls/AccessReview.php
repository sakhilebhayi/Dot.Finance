<?php

namespace App\Models\Controls;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A recorded confirmation that someone reviewed who has access to this ledger. Append-only evidence. */
class AccessReview extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'team_id', 'reviewed_by', 'reviewed_at', 'notes', 'snapshot', 'member_count'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'reviewed_at' => 'datetime', 'member_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Access reviews are evidence: they cannot be updated.'));
        static::deleting(fn () => throw new LogicException('Access reviews are evidence: they cannot be deleted.'));
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function auditLabel(): string
    {
        return 'Access review '.$this->reviewed_at?->toDateString();
    }
}
