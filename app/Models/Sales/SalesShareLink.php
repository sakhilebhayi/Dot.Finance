<?php

namespace App\Models\Sales;

use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A read-only, expiring, revocable link a customer opens without signing in. Only the hash of the token is stored. */
class SalesShareLink extends Model
{
    use HasLedgerContext;

    protected $guarded = [];

    protected $casts = ['options' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_viewed_at' => 'datetime', 'views' => 'integer'];

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

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
