<?php

namespace App\Models\Purchases;

use App\Casts\DateOnly;
use App\Models\Accounting\GlAccount;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** One receipt on an expense claim. Receipts are VAT inclusive: the gross is what was paid. */
class ExpenseClaimLine extends Model
{
    use HasLedgerContext;

    public const RECEIPT_TYPES = ['tax_invoice', 'abridged_invoice', 'none', 'unknown'];

    protected $fillable = [
        'user_id', 'team_id', 'expense_claim_id', 'line_no', 'spent_on', 'merchant', 'description', 'receipt_type', 'merchant_vat_number',
        'receipt_reference', 'gl_account_id', 'tax_code', 'tax_code_id', 'tax_rate_bp', 'dimension', 'gross_cents', 'net_cents', 'tax_cents',
        'claimable_tax_cents', 'claim_reason',
    ];

    protected $casts = [
        'spent_on' => DateOnly::class,
        'line_no' => 'integer',
        'tax_rate_bp' => 'integer',
        'gross_cents' => 'integer',
        'net_cents' => 'integer',
        'tax_cents' => 'integer',
        'claimable_tax_cents' => 'integer',
    ];

    public function ledgerReferences(): array
    {
        return ['expense_claim_id' => ExpenseClaim::class, 'gl_account_id' => GlAccount::class];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
