<?php

namespace App\Models\Shared;

use App\Casts\DateOnly;
use App\Models\Account;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Money that moved. Recorded once (idempotency key unique per ledger), never edited and never deleted:
 * a mistake is reversed with a reason. Only the links the posting modules write afterwards
 * (journal entry, cash-ledger row) and the reversal fields may change. This model posts nothing.
 */
class Payment extends Model
{
    use HasLedgerContext;

    public const DIRECTIONS = ['in', 'out'];

    public const METHODS = ['eft', 'card', 'cash', 'cheque', 'debit_order', 'other'];

    protected $fillable = [
        'user_id', 'team_id', 'direction', 'party_id', 'cash_account_id', 'amount_cents', 'currency', 'fx_rate_e8',
        'base_amount_cents', 'paid_on', 'method', 'reference', 'idempotency_key', 'status', 'reversed_at',
        'reversal_reason', 'reversed_by', 'gl_entry_id', 'transaction_id', 'recorded_by', 'notes',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'fx_rate_e8' => 'integer',
        'base_amount_cents' => 'integer',
        'paid_on' => DateOnly::class,
        'reversed_at' => 'datetime',
        'gl_entry_id' => 'integer',
    ];

    private const MUTABLE = ['status', 'reversed_at', 'reversal_reason', 'reversed_by', 'gl_entry_id', 'transaction_id', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $payment) {
            foreach (array_keys($payment->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE, true)) {
                    throw new LogicException("A recorded payment's {$column} cannot be edited. Reverse the payment and record it again.");
                }
            }

            if ($payment->isDirty('status') && ! ($payment->getOriginal('status') === 'recorded' && $payment->status === 'reversed')) {
                throw new LogicException('A payment can only move from recorded to reversed, never back.');
            }
        });

        static::deleting(fn () => throw new LogicException('Payments are never deleted: reverse them instead.'));
    }

    public function ledgerReferences(): array
    {
        return ['party_id' => Party::class, 'cash_account_id' => Account::class, 'transaction_id' => Transaction::class];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id')->withTrashed();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    /** What is not yet applied to anything: amount minus its live allocations. */
    public function unallocatedCents(): int
    {
        return (int) $this->amount_cents - (int) $this->allocations()->whereNull('released_at')->sum('amount_cents');
    }
}
