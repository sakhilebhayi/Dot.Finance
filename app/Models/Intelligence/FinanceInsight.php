<?php

namespace App\Models\Intelligence;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finding the detectors made from the ledger's own data: what, how serious, and the rows that prove it.
 * Always "Calculated": no model is involved in deciding that something is unusual.
 */
class FinanceInsight extends Model
{
    use HasLedgerContext;

    public const SEVERITIES = ['info' => 0, 'notice' => 1, 'warning' => 2, 'critical' => 3];

    protected $fillable = [
        'user_id', 'team_id', 'kind', 'subject_key', 'period_key', 'severity', 'title', 'body', 'evidence', 'href', 'basis',
        'status', 'snoozed_until', 'handled_by', 'handled_at', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'snoozed_until' => 'date',
        'handled_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** What the feed shows: open, or snoozed and the snooze has run out. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', 'open')
                ->orWhere(fn (Builder $s) => $s->where('status', 'snoozed')->where('snoozed_until', '<=', now()->toDateString()));
        });
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function severityRank(): int
    {
        return self::SEVERITIES[$this->severity] ?? 0;
    }

    public function auditLabel(): string
    {
        return 'Finding '.str_replace('_', ' ', $this->kind);
    }
}
