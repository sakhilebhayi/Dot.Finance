<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A saved snapshot of an income-tax estimate (history). Append-only. */
class TaxIncomeEstimate extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'team_id', 'tax_year_end', 'label', 'regime', 'figures', 'figures_hash', 'taxable_income_cents', 'tax_cents',
        'rule_versions', 'created_by_user_id',
    ];

    protected $casts = [
        'tax_year_end' => DateOnly::class, 'figures' => 'array', 'rule_versions' => 'array',
        'taxable_income_cents' => 'integer', 'tax_cents' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A saved estimate is a snapshot and does not change; save a new one.'));
        static::deleting(fn () => throw new LogicException('A saved estimate is a snapshot and is kept.'));
    }
}
