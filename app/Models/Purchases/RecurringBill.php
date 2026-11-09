<?php

namespace App\Models\Purchases;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bill that repeats (rent, a subscription). It makes DRAFT bills on schedule: they still need review, approval and posting. */
class RecurringBill extends Model
{
    use HasLedgerContext;

    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    protected $fillable = [
        'user_id', 'team_id', 'party_id', 'name', 'frequency', 'interval_count', 'start_on', 'next_run_on', 'end_on', 'status', 'days_until_due',
        'currency', 'tax_inclusive', 'document_type', 'template', 'last_generated_on', 'created_by',
    ];

    protected $casts = [
        'start_on' => DateOnly::class,
        'next_run_on' => DateOnly::class,
        'end_on' => DateOnly::class,
        'last_generated_on' => DateOnly::class,
        'interval_count' => 'integer',
        'days_until_due' => 'integer',
        'tax_inclusive' => 'boolean',
        'template' => 'array',
    ];

    public function ledgerReferences(): array
    {
        return ['party_id' => Party::class];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function auditLabel(): string
    {
        return 'Recurring bill #'.$this->id.' ('.$this->frequency.')';
    }
}
