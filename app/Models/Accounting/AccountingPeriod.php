<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/** A calendar month of the fiscal year. Closing it locks the books through its last day. */
class AccountingPeriod extends Model
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'start_date', 'end_date', 'fiscal_year', 'is_year_end', 'status', 'closed_at', 'closed_by_user_id',
        'closing_entry_id', 'reopened_at', 'reopened_by_user_id', 'reopen_reason',
    ];

    protected $casts = [
        'start_date' => DateOnly::class,
        'end_date' => DateOnly::class,
        'fiscal_year' => 'integer',
        'is_year_end' => 'boolean',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function label(): string
    {
        return $this->start_date->format('F Y');
    }
}
