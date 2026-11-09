<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxPayrollLine;
use App\Models\Tax\TaxPayrollRun;
use App\Models\User;
use App\Services\Tax\Exceptions\TaxException;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Captures the payroll tax summary of a month by hand. One summary per month. A DRAFT can be replaced; a POSTED one
 * (its payroll journal exists) is never touched: the journal is reversed from the accounting pages if it was wrong.
 */
final class PayrollRuns
{
    /**
     * @param  array{gross_pay_cents: int, paye_cents: int, uif_employee_cents: int, uif_employer_cents: int, sdl_cents: int, eti_claimed_cents: int, employee_count: int, notes?: ?string}  $values
     */
    public function capture(LedgerContext $ctx, CarbonImmutable $month, array $values, ?User $by = null): TaxPayrollRun
    {
        $month = $month->startOfMonth();

        return DB::transaction(function () use ($ctx, $month, $values, $by) {
            $run = TaxPayrollRun::query()->forContext($ctx)->where('period_month', $month->toDateString())->lockForUpdate()->first();

            if ($run?->isPosted()) {
                throw new TaxException('The payroll for '.$month->format('F Y').' is already posted to the books and is never overwritten. Reverse its journal first if it was wrong.');
            }
            if ($run && $run->source === 'hr_import') {
                throw new TaxException('The payroll for '.$month->format('F Y').' was imported from Dot.HR. Change it in Dot.HR and import again; it is not edited here.');
            }
            if ($values['paye_cents'] > $values['gross_pay_cents']) {
                throw new TaxException('PAYE cannot be more than gross pay.');
            }
            if ($values['gross_pay_cents'] < $values['paye_cents'] + $values['uif_employee_cents']) {
                throw new TaxException('PAYE and employee UIF together cannot be more than gross pay.');
            }

            $attributes = [
                'period_month' => $month->toDateString(), 'source' => 'manual', 'status' => 'draft',
                'gross_pay_cents' => $values['gross_pay_cents'], 'paye_cents' => $values['paye_cents'],
                'uif_employee_cents' => $values['uif_employee_cents'], 'uif_employer_cents' => $values['uif_employer_cents'],
                'sdl_cents' => $values['sdl_cents'], 'eti_claimed_cents' => $values['eti_claimed_cents'],
                'employee_count' => $values['employee_count'], 'entered_by_user_id' => $by?->id, 'notes' => $values['notes'] ?? null,
            ];

            if ($run === null) {
                $run = TaxPayrollRun::query()->create($ctx->ownerAttributes() + $attributes);
            } else {
                $run->fill($attributes)->save();
            }

            FinanceAudit::record($ctx->ownerAttributes(), $run->wasRecentlyCreated ? 'created' : 'updated', 'tax_payroll', (int) $run->id, 'Payroll tax summary '.$month->format('Y-m'), [
                'gross_pay_cents' => ['after' => $run->gross_pay_cents], 'paye_cents' => ['after' => $run->paye_cents],
                'uif_employee_cents' => ['after' => $run->uif_employee_cents], 'sdl_cents' => ['after' => $run->sdl_cents],
            ], null, null, $by?->id);

            return $run;
        });
    }

    /**
     * Replace the employee lines of a draft run (HR import).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function replaceLines(LedgerContext $ctx, TaxPayrollRun $run, array $lines): void
    {
        if ($run->isPosted()) {
            throw new TaxException('A posted payroll run is never changed.');
        }

        TaxPayrollLine::query()->forContext($ctx)->where('tax_payroll_run_id', $run->id)->each(fn ($l) => $l->delete());
        foreach ($lines as $l) {
            TaxPayrollLine::query()->create($ctx->ownerAttributes() + [
                'tax_payroll_run_id' => $run->id, 'employee_ref' => $l['ref'], 'employee_name' => $l['name'] ?? null,
                'gross_cents' => $l['gross_cents'], 'paye_cents' => $l['paye_cents'], 'uif_employee_cents' => $l['uif_employee_cents'],
                'uif_employer_cents' => $l['uif_employer_cents'], 'sdl_cents' => $l['sdl_cents'], 'eti_cents' => $l['eti_cents'] ?? 0,
            ]);
        }
    }
}
