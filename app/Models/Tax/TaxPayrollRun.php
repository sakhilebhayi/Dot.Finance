<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The payroll tax summary of one month (gross pay, PAYE, UIF, SDL, ETI). Captured by hand or imported from Dot.HR.
 * A draft can be replaced; a posted run (its payroll journal exists) is never overwritten.
 */
class TaxPayrollRun extends Model
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'period_month', 'source', 'hr_run_id', 'status', 'gross_pay_cents', 'paye_cents', 'uif_employee_cents',
        'uif_employer_cents', 'sdl_cents', 'eti_claimed_cents', 'employee_count', 'journal_entry_id', 'posted_at', 'posted_by_user_id',
        'payload_hash', 'imported_at', 'entered_by_user_id', 'notes',
    ];

    protected $casts = [
        'period_month' => DateOnly::class, 'posted_at' => 'datetime', 'imported_at' => 'datetime',
        'gross_pay_cents' => 'integer', 'paye_cents' => 'integer', 'uif_employee_cents' => 'integer', 'uif_employer_cents' => 'integer',
        'sdl_cents' => 'integer', 'eti_claimed_cents' => 'integer', 'employee_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $run) {
            if ($run->getOriginal('status') === 'posted') {
                throw new LogicException('A posted payroll run is never changed. Its journal can be reversed from the accounting pages.');
            }
        });
        static::deleting(function (self $run) {
            if ($run->status === 'posted') {
                throw new LogicException('A posted payroll run is never deleted.');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TaxPayrollLine::class, 'tax_payroll_run_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function netPayCents(): int
    {
        return $this->gross_pay_cents - $this->paye_cents - $this->uif_employee_cents;
    }

    /** What is owed to SARS for the month through the EMP201: PAYE + UIF (both parties) + SDL, less the ETI claimed. */
    public function emp201LiabilityCents(): int
    {
        return $this->paye_cents + $this->uif_employee_cents + $this->uif_employer_cents + $this->sdl_cents - $this->eti_claimed_cents;
    }
}
