<?php

namespace App\Models\Bank;

use App\Models\Account;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A bank charge, interest or authorised variance made during a reconciliation. The money itself is an ordinary cash-ledger
 * row (so it posts to the books through the normal path); this row says why it exists. Append-only.
 */
class BankAdjustment extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    public const KINDS = ['bank_charge', 'interest', 'variance', 'other'];

    protected $fillable = ['user_id', 'team_id', 'bank_reconciliation_id', 'account_id', 'kind', 'direction', 'amount_cents', 'transaction_id', 'bank_statement_line_id', 'reason', 'created_by'];

    protected $casts = ['amount_cents' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reconciliation adjustments are append-only.'));
        static::deleting(fn () => throw new LogicException('Reconciliation adjustments are append-only.'));
    }

    public function ledgerReferences(): array
    {
        return ['account_id' => Account::class, 'bank_reconciliation_id' => BankReconciliation::class, 'transaction_id' => Transaction::class];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withTrashed();
    }

    public function label(): string
    {
        return ['bank_charge' => 'Bank charge', 'interest' => 'Interest', 'variance' => 'Variance', 'other' => 'Other adjustment'][$this->kind] ?? ucfirst($this->kind);
    }
}
