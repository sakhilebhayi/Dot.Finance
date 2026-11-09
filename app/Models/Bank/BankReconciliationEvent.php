<?php

namespace App\Models\Bank;

use App\Models\Account;
use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One thing that happened in a reconciliation session: started, matched, unmatched, adjusted, completed, reopened.
 * Append-only, on both engines. The history of a reconciliation is this table plus the audit log.
 */
class BankReconciliationEvent extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'team_id', 'bank_reconciliation_id', 'account_id', 'event', 'actor_id', 'data'];

    protected $casts = ['data' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reconciliation history is append-only.'));
        static::deleting(fn () => throw new LogicException('Reconciliation history is append-only.'));
    }

    public function ledgerReferences(): array
    {
        return ['account_id' => Account::class, 'bank_reconciliation_id' => BankReconciliation::class];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
