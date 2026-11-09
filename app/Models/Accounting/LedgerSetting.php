<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per ledger: the base currency, the fiscal calendar, whether accounting is on, how far the
 * books are closed, and the next journal entry number. The row doubles as the lock that serialises
 * posting (entry numbers are gap-free because every post takes this row FOR UPDATE).
 */
class LedgerSetting extends Model
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'base_currency', 'fiscal_year_start_month', 'chart_template', 'accounting_enabled_at',
        'accounting_start_date', 'books_closed_through',
    ];

    protected $casts = [
        'fiscal_year_start_month' => 'integer',
        'next_entry_number' => 'integer',
        'accounting_enabled_at' => 'datetime',
        'accounting_start_date' => DateOnly::class,
        'books_closed_through' => DateOnly::class,
        'posting_rule_version' => 'integer',
    ];

    public function isEnabled(): bool
    {
        return $this->accounting_enabled_at !== null;
    }
}
