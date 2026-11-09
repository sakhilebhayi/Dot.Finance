<?php

namespace App\Models\Bank;

use App\Models\Account;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A group of statement lines and cash-ledger rows that say the same thing: the signed amounts on each side add up to
 * the same figure. One to one is the common case; one line against several rows, or several lines against one row, are
 * splits. A match is released (with who and why), never deleted; the ids it held are kept in the snapshot.
 */
class BankMatch extends Model
{
    use HasLedgerContext;

    public const METHODS = ['auto', 'manual', 'rule', 'created', 'transfer', 'variance'];

    protected $fillable = [
        'user_id', 'team_id', 'account_id', 'bank_reconciliation_id', 'ref', 'method', 'shape', 'confidence', 'reason', 'amount_cents',
        'line_count', 'transaction_count', 'state', 'matched_by', 'matched_at', 'released_at', 'released_by', 'release_reason', 'snapshot',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'confidence' => 'integer',
        'line_count' => 'integer',
        'transaction_count' => 'integer',
        'matched_at' => 'datetime',
        'released_at' => 'datetime',
        'snapshot' => 'array',
    ];

    private const MUTABLE = ['state', 'released_at', 'released_by', 'release_reason', 'snapshot', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $match) {
            foreach (array_keys($match->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE, true)) {
                    throw new LogicException("A bank match's {$column} cannot be edited. Release it and match again.");
                }
            }
        });

        static::deleting(fn () => throw new LogicException('Bank matches are never deleted: release them instead.'));
    }

    public function ledgerReferences(): array
    {
        return ['account_id' => Account::class, 'bank_reconciliation_id' => BankReconciliation::class];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'bank_match_id');
    }

    public function isActive(): bool
    {
        return $this->state === 'active';
    }
}
