<?php

namespace App\Models\Integrations;

use App\Models\Accounting\JournalEntry;
use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Dot.Emall order line waiting for review. Staging only: nothing here is in the books until a person with
 * the posting capability approves it, and then exactly one journal entry exists for it.
 */
class EmallSaleLine extends Model
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'inbound_event_id', 'order_item_id', 'order_ref', 'seller_ref', 'sold_on', 'currency', 'description',
        'quantity', 'gross_cents', 'vat_cents', 'fee_cents', 'tax_code', 'claimed', 'status', 'problems', 'journal_entry_id',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'sold_on' => 'date',
        'claimed' => 'array',
        'problems' => 'array',
        'gross_cents' => 'integer',
        'vat_cents' => 'integer',
        'fee_cents' => 'integer',
        'quantity' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function ledgerReferences(): array
    {
        return ['inbound_event_id' => InboundEvent::class, 'journal_entry_id' => JournalEntry::class];
    }

    public function netCents(): int
    {
        return $this->gross_cents - $this->vat_cents;
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function auditLabel(): string
    {
        return 'Emall sale '.$this->order_item_id;
    }
}
