<?php

namespace App\Models\Intelligence;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message to one person about one thing in one ledger. The bell and the notifications page read these. */
class FinanceNotification extends Model
{
    use HasLedgerContext;

    public const KINDS = [
        'payment_reminder' => 'Payment reminders',
        'tax_deadline' => 'Tax deadlines',
        'approval_waiting' => 'Approvals waiting for you',
        'overdue_summary' => 'Overdue summaries',
        'insight' => 'Things that need attention',
        'weekly_digest' => 'The weekly digest',
        'system' => 'System messages',
    ];

    protected $fillable = [
        'user_id', 'team_id', 'recipient_id', 'kind', 'severity', 'title', 'body', 'href', 'dedupe_key', 'read_at',
        'emailed_at', 'email_status', 'notify_status',
    ];

    protected $casts = ['read_at' => 'datetime', 'emailed_at' => 'datetime'];

    public function scopeFor(Builder $query, User|int $user): Builder
    {
        return $query->where($this->getTable().'.recipient_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull($this->getTable().'.read_at');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
