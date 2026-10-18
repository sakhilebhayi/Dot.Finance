<?php

namespace App\Models;

use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable line in a user's finance history: who did what to which record,
 * and, for fields that are not free text, what the value was before and after.
 *
 * Append-only by construction: the model refuses update() and delete() and the
 * builder refuses bulk update/delete (see AppendOnlyBuilder). Rows are written
 * by FinanceAuditObserver and by the CSV importer, never by a controller.
 */
class FinanceAuditLog extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $table = 'finance_audit_log';

    protected $guarded = [];

    protected $casts = ['changes' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The finance audit log is append-only: rows cannot be updated.'));
        static::deleting(fn () => throw new LogicException('The finance audit log is append-only: rows cannot be deleted.'));
    }

    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        return new AppendOnlyBuilder($query);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The person who made the change (null for a scheduled or system change). */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
