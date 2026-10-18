<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use App\Support\Money;
use Database\Factories\RecurringRuleFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A repeating income or expense: the template the scheduler copies into the
 * ledger each period. Ledger entries it generates are ordinary transactions that
 * point back at it (transactions.recurring_rule_id); editing the rule only ever
 * changes what is generated from then on.
 */
class RecurringRule extends Model
{
    /** @use HasFactory<RecurringRuleFactory> */
    use HasFactory, HasLedgerContext, SoftDeletes;

    protected $fillable = [
        'user_id', 'team_id', 'account_id', 'category_id', 'description', 'amount', 'amount_cents', 'currency', 'type', 'notes',
        'recurrence_type', 'recurrence_interval', 'start_date', 'next_occurrence_date', 'end_date', 'paused_at', 'ended_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'recurrence_interval' => 'integer',
        'start_date' => DateOnly::class,
        'next_occurrence_date' => DateOnly::class,
        'end_date' => DateOnly::class,
        'paused_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected function amount(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => Money::toDecimalString((int) ($attributes['amount_cents'] ?? 0)),
            set: fn ($value) => ['amount_cents' => Money::fromDecimal($value)],
        );
    }

    protected static function booted(): void
    {
        static::creating(function (RecurringRule $rule): void {
            if ($rule->currency === null && $rule->account_id !== null) {
                $rule->currency = DB::table('accounts')->where('id', $rule->account_id)->value('currency');
            }
        });
    }

    public function ledgerInheritsFrom(): ?string
    {
        return 'account_id';
    }

    public function ledgerReferences(): array
    {
        return ['account_id' => Account::class, 'category_id' => Category::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null && $this->ended_at === null;
    }

    public function isEnded(): bool
    {
        return $this->ended_at !== null || $this->next_occurrence_date === null;
    }

    public function isActive(): bool
    {
        return ! $this->isPaused() && ! $this->isEnded();
    }

    /** 'active' | 'paused' | 'ended' */
    public function status(): string
    {
        return $this->isEnded() ? 'ended' : ($this->isPaused() ? 'paused' : 'active');
    }

    public function cadenceLabel(): string
    {
        $unit = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$this->recurrence_type] ?? $this->recurrence_type;

        return $this->recurrence_interval === 1 ? 'Every '.$unit : 'Every '.$this->recurrence_interval.' '.$unit.'s';
    }
}
