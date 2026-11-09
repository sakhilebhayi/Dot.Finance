<?php

namespace App\Models\Bank;

use App\Casts\DateOnly;
use App\Models\Account;
use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reconciliation session for one cash account up to a statement date. While it is a draft, lines and ledger rows are
 * matched into it; it completes only when the statement's closing balance equals the ledger's cleared balance, and from
 * then on every row it cleared is locked. History lives in bank_reconciliation_events (append-only).
 */
class BankReconciliation extends Model
{
    use HasLedgerContext;

    public const STATUSES = ['draft', 'completed'];

    protected $fillable = [
        'user_id', 'team_id', 'account_id', 'bank_statement_id', 'status', 'statement_date', 'baseline_date', 'statement_opening_cents',
        'statement_closing_cents', 'currency', 'ledger_cleared_cents', 'difference_cents', 'variance_cents', 'variance_reason',
        'variance_by', 'variance_transaction_id', 'unmatched_accepted', 'snapshot', 'started_by', 'started_at', 'completed_by',
        'completed_at', 'reopened_count', 'last_reopened_at', 'last_reopen_reason',
    ];

    protected $casts = [
        'statement_date' => DateOnly::class,
        'baseline_date' => DateOnly::class,
        'statement_opening_cents' => 'integer',
        'statement_closing_cents' => 'integer',
        'ledger_cleared_cents' => 'integer',
        'difference_cents' => 'integer',
        'variance_cents' => 'integer',
        'unmatched_accepted' => 'integer',
        'reopened_count' => 'integer',
        'snapshot' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_reopened_at' => 'datetime',
    ];

    public function ledgerReferences(): array
    {
        return ['account_id' => Account::class, 'bank_statement_id' => BankStatement::class];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankMatch::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BankReconciliationEvent::class)->orderBy('id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BankAdjustment::class)->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function auditLabel(): string
    {
        return 'Reconciliation to '.$this->statement_date?->toDateString().' closing '.Money::format((int) $this->statement_closing_cents, $this->currency);
    }
}
