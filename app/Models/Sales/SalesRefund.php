<?php

namespace App\Models\Sales;

use App\Contracts\Allocatable;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money given back to a customer out of a credit they hold: a credit note not yet applied, or a payment received
 * and not yet applied. The money leaving is its own Payment (direction out). When the source is a payment, the
 * refund is an Allocatable that the source payment is allocated to, so PaymentAllocator's rules (never more than
 * the payment holds) keep protecting the credit.
 */
class SalesRefund extends Model implements Allocatable
{
    use HasLedgerContext;

    protected $guarded = [];

    protected $casts = [
        'amount_cents' => 'integer',
        'source_base_cents' => 'integer',
        'bank_base_cents' => 'integer',
        'reversed_at' => 'datetime',
    ];

    public function ledgerReferences(): array
    {
        return ['party_id' => Party::class, 'payment_id' => Payment::class, 'source_document_id' => SalesDocument::class];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function sourcePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'source_document_id');
    }

    public function allocatableTotalCents(): int
    {
        return $this->status === 'recorded' ? (int) $this->amount_cents : 0;
    }

    public function allocatableCurrency(): string
    {
        return $this->currency;
    }

    public function allocatablePartyId(): ?int
    {
        return (int) $this->party_id;
    }

    /** A refund is settled out of money that came in, so a received payment may be allocated to it. */
    public function allocatableDirection(): string
    {
        return 'in';
    }
}
