<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\HasUserScope;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, HasUserScope;

    /**
     * There is deliberately no `balance` here. The legacy accounts.balance column
     * is a record of what the user once typed; the balance is derived from the
     * ledger (see scopeWithBalance) and cannot be assigned.
     */
    protected $fillable = [
        'user_id', 'name', 'type', 'currency', 'institution',
        'opening_balance_cents', 'opening_balance_date',
    ];

    protected $casts = [
        'opening_balance_cents' => 'integer',
        'opening_balance_date' => DateOnly::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->latest('date');
    }

    /**
     * Current balance = opening balance + every signed transaction dated on or
     * after the opening date, computed in the database as one correlated
     * subquery per account (a single statement for the whole list).
     *
     * A null opening date means "no lower bound": every transaction counts.
     * Transactions dated before the opening date are history the opening
     * balance already absorbed; they are kept but never reconstructed.
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        $signed = Transaction::signedCentsSql('t');

        if (empty($query->getQuery()->columns)) {
            $query->select('accounts.*');
        }

        return $query->addSelect([
            DB::raw(
                "(accounts.opening_balance_cents + COALESCE((SELECT SUM({$signed}) FROM transactions t"
                .' WHERE t.account_id = accounts.id'
                .' AND (accounts.opening_balance_date IS NULL OR t.date >= accounts.opening_balance_date)'
                .'), 0)) AS derived_balance_cents'
            ),
        ]);
    }

    /**
     * The derived balance in cents. Free when the account was loaded through
     * withBalance(); otherwise one query for this account.
     */
    public function balanceCents(): int
    {
        if (array_key_exists('derived_balance_cents', $this->attributes)) {
            return (int) $this->attributes['derived_balance_cents'];
        }

        $fresh = static::withoutGlobalScope('user')->withBalance()->whereKey($this->getKey())->first();

        return (int) ($fresh?->attributes['derived_balance_cents'] ?? $this->opening_balance_cents);
    }

    public function hasTransactions(): bool
    {
        return Transaction::withoutGlobalScopes()->where('account_id', $this->id)->exists();
    }
}
