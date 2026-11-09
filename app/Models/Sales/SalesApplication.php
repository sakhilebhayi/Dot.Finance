<?php

namespace App\Models\Sales;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Something settled something: a payment (through its payment allocation) or a credit note was applied to an
 * invoice or debit note. It records the amount in the document currency and the base-currency value on each
 * side, so receivables can be added up in base currency exactly and a foreign-currency settlement knows its
 * realised exchange difference. Released, never edited or deleted.
 */
class SalesApplication extends Model
{
    use HasLedgerContext;

    protected $guarded = [];

    protected $casts = [
        'amount_cents' => 'integer',
        'target_base_cents' => 'integer',
        'source_base_cents' => 'integer',
        'fx_diff_base_cents' => 'integer',
        'applied_on' => DateOnly::class,
        'released_at' => 'datetime',
    ];

    private const MUTABLE = ['released_at', 'released_by', 'release_reason', 'fx_entry_id', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $application) {
            foreach (array_keys($application->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE, true)) {
                    throw new LogicException("An application's {$column} cannot be edited. Release it and apply again.");
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Applications are never deleted: release them.'));
    }

    public function ledgerReferences(): array
    {
        return ['payment_id' => Payment::class, 'allocation_id' => PaymentAllocation::class, 'source_document_id' => SalesDocument::class, 'target_document_id' => SalesDocument::class];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'target_document_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'source_document_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isLive(): bool
    {
        return $this->released_at === null;
    }
}
