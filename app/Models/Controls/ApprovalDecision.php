<?php

namespace App\Models\Controls;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One person's decision on one step of an approval request. Append-only (the database refuses updates too). */
class ApprovalDecision extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'step' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Approval decisions are evidence: they cannot be updated.'));
        static::deleting(fn () => throw new LogicException('Approval decisions are evidence: they cannot be deleted.'));
    }

    public function ledgerReferences(): array
    {
        return ['approval_request_id' => ApprovalRequest::class];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
