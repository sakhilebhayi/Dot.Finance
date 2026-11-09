<?php

namespace App\Models\Tax;

use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A manual adjustment to one box of a draft return (a bad debt, a credit note the books do not carry, an apportionment).
 * Always with a reason. Never deleted: it is voided with a reason, and only while the return is still a draft.
 */
class TaxReturnAdjustment extends Model
{
    use HasLedgerContext;

    public const KINDS = [
        'bad_debt' => 'Bad debt written off',
        'bad_debt_recovered' => 'Bad debt recovered',
        'credit_note' => 'Credit note not in the books',
        'debit_note' => 'Debit note not in the books',
        'apportionment' => 'Apportionment (mixed use)',
        'change_in_use' => 'Change in use',
        'correction' => 'Correction of an earlier period',
        'other' => 'Other',
    ];

    protected $fillable = [
        'user_id', 'team_id', 'tax_return_id', 'box_code', 'kind', 'amount_cents', 'reason', 'attachment_id', 'created_by_user_id',
        'voided_at', 'voided_by_user_id', 'void_reason',
    ];

    protected $casts = ['amount_cents' => 'integer', 'voided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Adjustments are voided with a reason, never deleted.'));
    }

    public function ledgerReferences(): array
    {
        return ['tax_return_id' => TaxReturn::class, 'attachment_id' => Attachment::class];
    }

    public function taxReturn(): BelongsTo
    {
        return $this->belongsTo(TaxReturn::class);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
