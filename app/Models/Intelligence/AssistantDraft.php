<?php

namespace App\Models\Intelligence;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the assistant proposes and a person may confirm. It is only ever a proposal: it changes nothing
 * until DraftExecutor::confirm() runs for the person who was shown it. See that class for what confirming does.
 */
class AssistantDraft extends Model
{
    use HasLedgerContext;

    public const KINDS = ['transaction', 'categorise', 'invoice_reminder'];

    protected $fillable = [
        'user_id', 'team_id', 'asker_id', 'message_id', 'kind', 'payload', 'summary', 'status', 'expires_at',
        'decided_by', 'decided_at', 'result_type', 'result_id', 'failure',
    ];

    protected $casts = ['payload' => 'array', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];

    public function ledgerReferences(): array
    {
        return ['message_id' => AssistantMessage::class];
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asker_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    public function auditLabel(): string
    {
        return 'Draft '.str_replace('_', ' ', $this->kind);
    }
}
