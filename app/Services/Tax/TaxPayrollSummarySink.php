<?php

namespace App\Services\Tax;

use App\Contracts\Integrations\PayrollSummarySink;
use App\Models\Team;
use App\Services\Integrations\Hr\PayrollSummary;
use App\Services\Tax\Exceptions\ImportRejected;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Where a validated Dot.HR payroll summary goes: into the Tax module's draft payroll run through the same import rules as the
 * signed API (idempotent on the run id, a posted run never overwritten, one summary per month). Creates a DRAFT; a person
 * posts the journal.
 */
final class TaxPayrollSummarySink implements PayrollSummarySink
{
    public function __construct(private readonly PayrollImport $import) {}

    public function accept(LedgerContext $context, PayrollSummary $summary): void
    {
        $team = $context->teamId !== null ? Team::query()->find($context->teamId) : null;
        if ($team === null || $team->infodot_team_uuid === null || strtolower((string) $team->infodot_team_uuid) !== $summary->companyUuid) {
            throw new ImportRejected('That payroll summary is not for this company.', 404);
        }

        $t = $summary->totals;
        $this->import->import([
            'schema' => PayrollImport::SCHEMA,
            'hr_run_id' => $summary->runId,
            'company_uuid' => $summary->companyUuid,
            'period' => substr($summary->periodStart, 0, 7),
            'currency' => $summary->currency,
            'issued_at' => CarbonImmutable::now()->toIso8601String(),
            'totals' => [
                'gross_pay_cents' => $t['gross_cents'], 'paye_cents' => $t['paye_cents'], 'uif_employee_cents' => $t['uif_employee_cents'],
                'uif_employer_cents' => $t['uif_employer_cents'], 'sdl_cents' => $t['sdl_cents'], 'eti_claimed_cents' => $t['eti_cents'] ?? 0,
                'employee_count' => $summary->employees,
            ],
        ]);
    }
}
