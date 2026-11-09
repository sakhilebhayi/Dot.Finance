<?php

namespace App\Models\Shared;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Part of a payment applied to something owed (an invoice, a bill). Not edited after creation:
 * it is released (released_at, with who and why), after which it no longer counts. Never deleted.
 * Create one only through PaymentAllocator, which holds the invariants.
 */
class PaymentAllocation extends Model
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'payment_id', 'allocatable_type', 'allocatable_id', 'amount_cents', 'tax_cents',
        'fx_diff_cents', 'allocated_by', 'released_at', 'released_by', 'release_reason',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'tax_cents' => 'integer',
        'fx_diff_cents' => 'integer',
        'released_at' => 'datetime',
    ];

    private const MUTABLE = ['released_at', 'released_by', 'release_reason', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $allocation) {
            foreach (array_keys($allocation->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE, true)) {
                    throw new LogicException("An allocation's {$column} cannot be edited. Release it and allocate again.");
                }
            }
            if ($allocation->isDirty('released_at') && $allocation->getOriginal('released_at') !== null) {
                throw new LogicException('An allocation is released once.');
            }
        });

        static::deleting(fn () => throw new LogicException('Allocations are never deleted: release them instead.'));
    }

    public function ledgerReferences(): array
    {
        return ['payment_id' => Payment::class];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function allocatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isLive(): bool
    {
        return $this->released_at === null;
    }
}
