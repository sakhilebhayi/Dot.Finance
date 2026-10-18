<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use App\Support\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * One line in the ledger. Money is amount_cents (integer, always positive) in
 * the account's currency; the sign comes from the type (and, for a transfer
 * leg, its direction). The old recurrence_* columns are no longer read: a series
 * lives in recurring_rules and a transaction points at it by recurring_rule_id.
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasLedgerContext, SoftDeletes;

    protected $fillable = [
        'account_id', 'category_id', 'user_id', 'team_id', 'description', 'amount', 'amount_cents', 'currency', 'type', 'date', 'notes',
        'transfer_direction', 'transfer_group', 'recurring_rule_id', 'import_fingerprint', 'import_batch_id',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'date' => DateOnly::class,
    ];

    /** @var array<string, true> transfer groups whose legs are mid-deletion (recursion guard) */
    protected static array $groupsBeingDeleted = [];

    protected static bool $cascadeTransferDeletes = true;

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
                $transaction->currency = DB::table('accounts')->where('id', $transaction->account_id)->value('currency');
            }
        });

        static::saving(function (Transaction $transaction): void {
            $transaction->attributes['amount'] = Money::toDecimalString((int) ($transaction->attributes['amount_cents'] ?? 0));
        });

        // A transfer is one event with two legs: removing either removes both,
        // so a balance can never be left holding half of a movement.
        static::deleting(function (Transaction $transaction): void {
            $group = $transaction->transfer_group;

            if ($group === null || ! static::$cascadeTransferDeletes || isset(static::$groupsBeingDeleted[$group])) {
                return;
            }

            static::$groupsBeingDeleted[$group] = true;

            try {
                static::query()->forContext($transaction->ledgerContext())
                    ->where('transfer_group', $group)
                    ->whereKeyNot($transaction->getKey())
                    ->get()
                    ->each->delete();
            } finally {
                unset(static::$groupsBeingDeleted[$group]);
            }
        });

        // A deleted import row must not keep blocking the same row from being
        // imported again, so its fingerprint is released.
        static::deleted(function (Transaction $transaction): void {
            if ($transaction->import_fingerprint !== null && ! $transaction->isForceDeleting()) {
                DB::table('transactions')->where('id', $transaction->id)->update(['import_fingerprint' => null]);
            }
        });
    }

    /**
     * Delete transactions without taking the other leg of a transfer with them.
     * Used when a whole account is deleted: the money that already moved to
     * another account stays there.
     */
    public static function withoutTransferCascade(callable $callback): mixed
    {
        $previous = static::$cascadeTransferDeletes;
        static::$cascadeTransferDeletes = false;

        try {
            return $callback();
        } finally {
            static::$cascadeTransferDeletes = $previous;
        }
    }

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

    public function ledgerInheritsFrom(): ?string
    {
        return 'account_id';
    }

    public function ledgerReferences(): array
    {
        return ['account_id' => Account::class, 'category_id' => Category::class, 'recurring_rule_id' => RecurringRule::class];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    /** Includes deleted categories so history keeps naming them. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recurringRule(): BelongsTo
    {
        return $this->belongsTo(RecurringRule::class)->withTrashed();
    }

    public function isRecurring(): bool
    {
        return $this->recurring_rule_id !== null;
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

    /** The other leg of this transfer, if it is still live. */
    public function transferPeer(): ?Transaction
    {
        if ($this->transfer_group === null) {
            return null;
        }

        return static::query()->forContext($this->ledgerContext())
            ->where('transfer_group', $this->transfer_group)
            ->whereKeyNot($this->getKey())
            ->first();
    }
}
