<?php

namespace App\Models\Shared;

use App\Casts\DateOnly;
use App\Models\Concerns\HasOptionalLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One observed exchange rate: 1 unit of from_currency = rate_e8 / 10^8 units of to_currency.
 * Append-only history: a correction is a new row (the lookup takes the newest), a row is
 * never edited or deleted. No owner = a global rate, one owner = that ledger's own.
 */
class FxRate extends Model
{
    use HasOptionalLedgerContext;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'team_id', 'from_currency', 'to_currency', 'rate_e8', 'as_of', 'source', 'fetched_at', 'created_by', 'note'];

    protected $casts = [
        'rate_e8' => 'integer',
        'as_of' => DateOnly::class,
        'fetched_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('FX rate history is append-only: add a new rate instead of editing one.'));
        static::deleting(fn () => throw new LogicException('FX rate history is append-only: rates are never deleted.'));
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
