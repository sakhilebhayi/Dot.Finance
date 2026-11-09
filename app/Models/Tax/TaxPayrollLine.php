<?php

namespace App\Models\Tax;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One employee's line of a payroll tax summary. SENSITIVE: the name is encrypted and only people with the payroll-detail capability see it. */
class TaxPayrollLine extends Model
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'tax_payroll_run_id', 'employee_ref', 'employee_name', 'gross_cents', 'paye_cents',
        'uif_employee_cents', 'uif_employer_cents', 'sdl_cents', 'eti_cents',
    ];

    protected $casts = [
        'employee_name' => 'encrypted',
        'gross_cents' => 'integer', 'paye_cents' => 'integer', 'uif_employee_cents' => 'integer',
        'uif_employer_cents' => 'integer', 'sdl_cents' => 'integer', 'eti_cents' => 'integer',
    ];

    public function ledgerReferences(): array
    {
        return ['tax_payroll_run_id' => TaxPayrollRun::class];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TaxPayrollRun::class, 'tax_payroll_run_id');
    }
}
