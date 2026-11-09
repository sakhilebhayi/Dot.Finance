<?php

namespace App\Models\Sales;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A template that produces an invoice on a schedule, as a draft or issued at once. One document per (template, period). */
class SalesRecurringTemplate extends Model
{
    use HasLedgerContext;

    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    protected $guarded = [];

    protected $casts = [
        'start_date' => DateOnly::class,
        'end_date' => DateOnly::class,
        'next_run_date' => DateOnly::class,
        'last_run_for' => DateOnly::class,
        'lines' => 'array',
        'prices_include_tax' => 'boolean',
        'every' => 'integer',
        'terms_days' => 'integer',
    ];

    public function ledgerReferences(): array
    {
        return ['party_id' => Party::class];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SalesDocument::class, 'recurring_template_id');
    }
}
