<?php

namespace App\Models\Sales;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The reminder log. The unique key (document, stage, offset) is what makes the scheduled command idempotent. */
class SalesReminder extends Model
{
    use HasLedgerContext;

    protected $guarded = [];

    protected $casts = ['scheduled_for' => DateOnly::class, 'handled_at' => 'datetime', 'offset_days' => 'integer'];

    public function ledgerReferences(): array
    {
        return ['document_id' => SalesDocument::class, 'party_id' => Party::class];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'document_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }
}
