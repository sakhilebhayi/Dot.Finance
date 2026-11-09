<?php

namespace App\Models\Sales;

use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Party;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An income transaction recorded the ordinary way, tied to a customer (and an invoice when it relates to one). A label only. */
class SalesTransactionLink extends Model
{
    use HasLedgerContext;

    protected $guarded = [];

    public function ledgerReferences(): array
    {
        return ['transaction_id' => Transaction::class, 'party_id' => Party::class, 'document_id' => SalesDocument::class];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withTrashed();
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'document_id');
    }
}
