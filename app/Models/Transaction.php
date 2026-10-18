<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\HasUserScope;
use App\Support\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasUserScope;

    protected $fillable = [
        'account_id', 'category_id', 'user_id', 'description', 'amount', 'amount_cents', 'currency', 'type', 'date', 'notes',
        'transfer_direction', 'transfer_group',
        'recurrence_type', 'recurrence_interval', 'next_occurrence_date', 'recurrence_parent_id',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'date' => DateOnly::class,
        'next_occurrence_date' => DateOnly::class,
        'recurrence_interval' => 'integer',
    ];

    /**
     * The always-positive magnitude, in the account's currency, as a decimal
     * string. amount_cents is the source of truth: setting `amount` converts a
     * decimal string to cents, and the legacy decimal column is mirrored from
     * the cents on save (see booted()) purely so old code keeps working.
     */
    protected function amount(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => Money::toDecimalString((int) ($attributes['amount_cents'] ?? 0)),
            set: fn ($value) => ['amount_cents' => Money::fromDecimal($value)],
        );
    }

    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction): void {
            if ($transaction->currency === null && $transaction->account_id !== null) {
                $transaction->currency = Account::withoutGlobalScopes()->whereKey($transaction->account_id)->value('currency');
            }
        });

        static::saving(function (Transaction $transaction): void {
            $transaction->attributes['amount'] = Money::toDecimalString((int) ($transaction->attributes['amount_cents'] ?? 0));
        });

        // A transfer is one event with two legs: removing either removes both,
        // so a balance can never be left holding half of a movement.
        static::deleting(function (Transaction $transaction): void {
            $group = $transaction->transfer_group;

            if ($group === null || isset(static::$groupsBeingDeleted[$group])) {
                return;
            }

            static::$groupsBeingDeleted[$group] = true;

            try {
                static::withoutGlobalScope('user')
                    ->where('transfer_group', $group)
                    ->whereKeyNot($transaction->getKey())
                    ->get()
                    ->each->delete();
            } finally {
                unset(static::$groupsBeingDeleted[$group]);
            }
        });
    }

    /** @var array<string, true> transfer groups whose legs are mid-deletion (recursion guard) */
    protected static array $groupsBeingDeleted = [];

    /**
     * SQL for a transaction's effect on its account, as a signed amount_cents:
     * income and the incoming transfer leg add, expense and the outgoing leg
     * subtract. A legacy transfer row (no direction, no destination) is zero:
     * it never had a balance effect and still doesn't.
     */
    public static function signedCentsSql(string $alias = 'transactions'): string
    {
        return "CASE WHEN {$alias}.type = 'income' THEN {$alias}.amount_cents"
            ." WHEN {$alias}.type = 'expense' THEN -{$alias}.amount_cents"
            ." WHEN {$alias}.type = 'transfer' AND {$alias}.transfer_direction = 'in' THEN {$alias}.amount_cents"
            ." WHEN {$alias}.type = 'transfer' AND {$alias}.transfer_direction = 'out' THEN -{$alias}.amount_cents"
            .' ELSE 0 END';
    }

    public function signedCents(): int
    {
        return match (true) {
            $this->type === 'income' => $this->amount_cents,
            $this->type === 'expense' => -$this->amount_cents,
            $this->type === 'transfer' && $this->transfer_direction === 'in' => $this->amount_cents,
            $this->type === 'transfer' && $this->transfer_direction === 'out' => -$this->amount_cents,
            default => 0,
        };
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_type !== null;
    }

    public function isTransfer(): bool
    {
        return $this->type === 'transfer';
    }

    /** A transfer leg that has a counterpart (new-style), as opposed to a legacy one-legged row. */
    public function isLinkedTransferLeg(): bool
    {
        return $this->type === 'transfer' && $this->transfer_group !== null;
    }

    /** The other leg of this transfer, if any. */
    public function transferPeer(): ?Transaction
    {
        if ($this->transfer_group === null) {
            return null;
        }

        return static::withoutGlobalScopes()
            ->where('transfer_group', $this->transfer_group)
            ->whereKeyNot($this->getKey())
            ->first();
    }

    /** The original transaction this one was generated from, if any. */
    public function recurrenceParent(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'recurrence_parent_id');
    }

    /** Every occurrence generated from this transaction (only populated when this is the anchor). */
    public function recurrenceOccurrences(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recurrence_parent_id');
    }
}
