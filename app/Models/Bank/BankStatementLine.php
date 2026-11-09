<?php

namespace App\Models\Bank;

use App\Casts\DateOnly;
use App\Models\Account;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * What the bank said, one line. Signed minor units in the account's currency: money in is positive, money out is
 * negative. Never edited: the only columns that ever change are the two links (the match it belongs to while a session
 * is open, the session it was reconciled in). A matched or reconciled line cannot be deleted.
 */
class BankStatementLine extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'team_id', 'bank_statement_id', 'account_id', 'line_no', 'posted_on', 'amount_cents', 'currency', 'description',
        'reference', 'external_id', 'balance_after_cents', 'fingerprint',
    ];

    protected $casts = [
        'posted_on' => DateOnly::class,
        'amount_cents' => 'integer',
        'balance_after_cents' => 'integer',
        'line_no' => 'integer',
    ];

    private const MUTABLE = ['bank_match_id', 'reconciliation_id'];

    protected static function booted(): void
    {
        static::updating(function (self $line) {
            foreach (array_keys($line->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE, true)) {
                    throw new LogicException("A bank statement line is what the bank said: its {$column} cannot be edited.");
                }
            }
        });

        static::deleting(function (self $line) {
            if ($line->bank_match_id !== null || $line->reconciliation_id !== null) {
                throw new LogicException('A matched or reconciled bank statement line cannot be deleted.');
            }
        });
    }

    public function ledgerInheritsFrom(): ?string
    {
        return 'account_id';
    }

    public function ledgerReferences(): array
    {
        return ['account_id' => Account::class, 'bank_statement_id' => BankStatement::class, 'bank_match_id' => BankMatch::class, 'reconciliation_id' => BankReconciliation::class];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(BankMatch::class, 'bank_match_id');
    }

    public function isMatched(): bool
    {
        return $this->bank_match_id !== null;
    }

    public function isReconciled(): bool
    {
        return $this->reconciliation_id !== null;
    }

    public function isMoneyIn(): bool
    {
        return $this->amount_cents > 0;
    }
}
