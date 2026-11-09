<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use App\Models\Accounting\GlAccount;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Attachment;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An income-tax adjustment the person entered for a tax year: a non-deductible amount added back, an extra deduction,
 * exempt income, an assessed loss brought forward, or a "reviewed, no adjustment" note. Always with a reason.
 * Voided with a reason, never deleted.
 */
class TaxAdjustment extends Model
{
    use HasLedgerContext;

    public const KINDS = [
        'add_back' => 'Add back (not deductible)',
        'deduction' => 'Additional deduction',
        'exempt_income' => 'Exempt or non-taxable income',
        'assessed_loss_bf' => 'Assessed loss brought forward',
        'review_only' => 'Reviewed, no adjustment',
    ];

    protected $fillable = [
        'user_id', 'team_id', 'tax_year_end', 'kind', 'category', 'label', 'amount_cents', 'reason', 'gl_account_id', 'attachment_id',
        'created_by_user_id', 'voided_at', 'voided_by_user_id', 'void_reason',
    ];

    protected $casts = ['tax_year_end' => DateOnly::class, 'amount_cents' => 'integer', 'voided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Tax adjustments are voided with a reason, never deleted.'));
    }

    public function ledgerReferences(): array
    {
        return ['gl_account_id' => GlAccount::class, 'attachment_id' => Attachment::class];
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
