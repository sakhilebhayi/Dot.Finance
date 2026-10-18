<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use App\Services\BudgetSpendCalculator;
use App\Support\Money;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory, HasUserScope;

    protected $fillable = ['user_id', 'category_id', 'amount', 'amount_cents', 'currency', 'month', 'year'];

    protected $casts = ['amount_cents' => 'integer', 'month' => 'integer', 'year' => 'integer'];

    protected function amount(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => Money::toDecimalString((int) ($attributes['amount_cents'] ?? 0)),
            set: fn ($value) => ['amount_cents' => Money::fromDecimal($value)],
        );
    }

    protected static function booted(): void
    {
        static::saving(function (Budget $budget): void {
            $budget->attributes['amount'] = Money::toDecimalString((int) ($budget->attributes['amount_cents'] ?? 0));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Expense spent against this budget in its own category, currency and month.
     * Prefer BudgetSpendCalculator::attach() on a whole list: this single-budget
     * fallback exists for callers that hold just one.
     */
    public function spentCents(): int
    {
        if (array_key_exists('spent_cents', $this->attributes)) {
            return (int) $this->attributes['spent_cents'];
        }

        return (new BudgetSpendCalculator)->forBudgets(collect([$this]))[$this->id] ?? 0;
    }
}
