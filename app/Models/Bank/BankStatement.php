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
 * One imported statement file for one cash account: the period it covers, the balances the bank printed (when the
 * file carried them), the hash of the file and what the import did. The lines are in bank_statement_lines.
 */
class BankStatement extends Model
{
    use HasLedgerContext;

    public const SOURCES = ['csv', 'ofx', 'qif', 'manual'];

    protected $fillable = [
        'user_id', 'team_id', 'account_id', 'source', 'original_name', 'file_sha256', 'currency', 'date_from', 'date_to',
        'opening_balance_cents', 'closing_balance_cents', 'balances_foot', 'line_count', 'duplicate_count', 'in_cents', 'out_cents',
        'mapping', 'imported_by',
    ];

    protected $casts = [
        'date_from' => DateOnly::class,
        'date_to' => DateOnly::class,
        'opening_balance_cents' => 'integer',
        'closing_balance_cents' => 'integer',
        'balances_foot' => 'boolean',
        'line_count' => 'integer',
        'duplicate_count' => 'integer',
        'in_cents' => 'integer',
        'out_cents' => 'integer',
        'mapping' => 'array',
    ];

    public function ledgerReferences(): array
    {
        return ['account_id' => Account::class];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('line_no');
    }

    public function auditLabel(): string
    {
        return 'Statement '.$this->date_from?->toDateString().' to '.$this->date_to?->toDateString().' ('.$this->line_count.' lines)';
    }

    public function netCents(): int
    {
        return (int) $this->in_cents + (int) $this->out_cents;
    }

    public function describeBalances(): string
    {
        if ($this->opening_balance_cents === null || $this->closing_balance_cents === null) {
            return 'Balances not in the file';
        }

        return Money::format($this->opening_balance_cents, $this->currency).' to '.Money::format($this->closing_balance_cents, $this->currency);
    }
}
