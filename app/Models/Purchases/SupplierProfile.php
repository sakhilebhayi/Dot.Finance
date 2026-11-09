<?php

namespace App\Models\Purchases;

use App\Models\Accounting\GlAccount;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What Purchases knows about a supplier beyond the shared party: defaults for new bills and early-payment terms. Bank details live apart (SupplierBankDetail). */
class SupplierProfile extends Model
{
    use HasLedgerContext;

    protected $fillable = ['user_id', 'team_id', 'party_id', 'default_gl_account_id', 'default_tax_code', 'early_payment_discount_bp', 'early_payment_days'];

    protected $casts = ['early_payment_discount_bp' => 'integer', 'early_payment_days' => 'integer'];

    public function ledgerReferences(): array
    {
        return ['party_id' => Party::class, 'default_gl_account_id' => GlAccount::class];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function auditLabel(): string
    {
        return 'Supplier profile for party #'.$this->party_id;
    }
}
