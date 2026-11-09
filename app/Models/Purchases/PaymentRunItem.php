<?php

namespace App\Models\Purchases;

use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRunItem extends Model
{
    use HasLedgerContext;

    protected $fillable = ['user_id', 'team_id', 'payment_run_id', 'party_id', 'allocatable_type', 'allocatable_id', 'amount_cents', 'payment_id'];

    protected $casts = ['amount_cents' => 'integer'];

    public function ledgerReferences(): array
    {
        return ['payment_run_id' => PaymentRun::class, 'party_id' => Party::class, 'payment_id' => Payment::class];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PaymentRun::class, 'payment_run_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }
}
