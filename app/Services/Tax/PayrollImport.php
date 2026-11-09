<?php

namespace App\Services\Tax;

use App\Models\Team;
use App\Models\Tax\TaxPayrollRun;
use App\Services\Accounting\GlSync;
use App\Services\Tax\Exceptions\ImportRejected;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The Dot.HR payroll summary import contract (schema dot.hr.payroll-summary.v1).
 *
 *   POST /api/tax/payroll-import           header  X-Dot-Signature: sha256=<hmac of the raw body, shared secret>
 *   {
 *     "schema": "dot.hr.payroll-summary.v1",
 *     "hr_run_id": "HR-2026-10",            unique per business; makes the import idempotent
 *     "company_uuid": "<InfoDot team uuid>", maps the sender's business to exactly one ledger
 *     "period": "2026-10",
 *     "currency": "ZAR",
 *     "issued_at": "2026-11-02T08:00:00Z",   must be within max_age_minutes of now (replay protection)
 *     "totals": {"gross_pay_cents": 0, "paye_cents": 0, "uif_employee_cents": 0, "uif_employer_cents": 0, "sdl_cents": 0, "eti_claimed_cents": 0, "employee_count": 0},
 *     "employees": [ {"ref": "E-001", "name": "optional", "gross_cents": 0, "paye_cents": 0, "uif_employee_cents": 0, "uif_employer_cents": 0, "sdl_cents": 0, "eti_cents": 0} ]   optional
 *   }
 *
 * Behaviour: signature checked first; the company uuid is mapped to a ledger (an unknown uuid is a 404-like refusal, nothing is
 * created); the totals are validated, and when employees are present they must add up to the totals; the same hr_run_id with the
 * same content is a no-op; the same hr_run_id with changed content replaces a DRAFT but is refused for a POSTED run (never
 * overwritten); a different hr_run_id for a month that already has a summary is a conflict. An import creates a DRAFT: the
 * payroll journal is posted by a person.
 *
 * STATUS: contract defined and tested. Dot.HR does not hold salary or payroll data today, so no sender exists.
 */
final class PayrollImport
{
    public const SCHEMA = 'dot.hr.payroll-summary.v1';

    public function __construct(private readonly PayrollRuns $runs, private readonly GlSync $sync) {}

    public function verifySignature(string $rawBody, ?string $header): bool
    {
        $secret = config('tax.payroll_import.secret');
        if (! is_string($secret) || $secret === '' || $header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($header, 7));
    }

    public function isConfigured(): bool
    {
        return is_string(config('tax.payroll_import.secret')) && config('tax.payroll_import.secret') !== '';
    }

    /**
     * @param  array<string, mixed>  $payload  the decoded body, signature already verified
     * @return array{status: string, run: TaxPayrollRun}
     */
    public function import(array $payload, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $data = $this->validate($payload, $now);

        $team = Team::query()->where('infodot_team_uuid', $data['company_uuid'])->first();
        if ($team === null || ! $team->isCompany()) {
            throw new ImportRejected('That company is not known here.', 404);
        }
        $ctx = LedgerContext::company($team);

        return LedgerContext::run($ctx, function () use ($ctx, $data) {
            $settings = $this->sync->settings($ctx);
            if ($settings !== null && $settings->base_currency !== $data['currency']) {
                throw new ImportRejected("The ledger's currency is {$settings->base_currency}; the payload is in {$data['currency']}.", 422);
            }

            $month = CarbonImmutable::createFromFormat('!Y-m', $data['period'])->startOfMonth();
            $hash = hash('sha256', json_encode([$data['totals'], $data['employees'] ?? []], JSON_THROW_ON_ERROR));

            return DB::transaction(function () use ($ctx, $data, $month, $hash) {
                $byHr = TaxPayrollRun::query()->forContext($ctx)->where('hr_run_id', $data['hr_run_id'])->lockForUpdate()->first();
                $byMonth = TaxPayrollRun::query()->forContext($ctx)->where('period_month', $month->toDateString())->lockForUpdate()->first();

                if ($byHr !== null) {
                    if ($byHr->payload_hash === $hash) {
                        return ['status' => 'unchanged', 'run' => $byHr];
                    }
                    if ($byHr->isPosted()) {
                        throw new ImportRejected('That payroll run is already posted to the books and is never overwritten. Reverse its journal first.', 409);
                    }
                    if ($byHr->period_month->toDateString() !== $month->toDateString()) {
                        throw new ImportRejected('That hr_run_id was imported for a different month.', 409);
                    }
                    $this->fill($byHr, $data, $hash);
                    $byHr->save();
                    $this->replaceLines($ctx, $byHr, $data);
                    $this->audit($ctx, $byHr, 'replaced');

                    return ['status' => 'replaced_draft', 'run' => $byHr];
                }

                if ($byMonth !== null) {
                    throw new ImportRejected('The summary for '.$month->format('F Y').' already exists under another run. It is not overwritten.', 409);
                }

                $run = new TaxPayrollRun($ctx->ownerAttributes() + ['period_month' => $month->toDateString(), 'status' => 'draft']);
                $this->fill($run, $data, $hash);
                $run->save();
                $this->replaceLines($ctx, $run, $data);
                $this->audit($ctx, $run, 'imported');

                return ['status' => 'created', 'run' => $run];
            });
        });
    }

    /** @return array<string, mixed> */
    private function validate(array $payload, CarbonImmutable $now): array
    {
        $validator = Validator::make($payload, [
            'schema' => ['required', 'in:'.self::SCHEMA],
            'hr_run_id' => ['required', 'string', 'max:64'],
            'company_uuid' => ['required', 'uuid'],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'issued_at' => ['required', 'date'],
            'totals' => ['required', 'array'],
            'totals.gross_pay_cents' => ['required', 'integer', 'min:0'],
            'totals.paye_cents' => ['required', 'integer', 'min:0'],
            'totals.uif_employee_cents' => ['required', 'integer', 'min:0'],
            'totals.uif_employer_cents' => ['required', 'integer', 'min:0'],
            'totals.sdl_cents' => ['required', 'integer', 'min:0'],
            'totals.eti_claimed_cents' => ['required', 'integer', 'min:0'],
            'totals.employee_count' => ['required', 'integer', 'min:0'],
            'employees' => ['sometimes', 'array', 'max:'.(int) config('tax.payroll_import.max_employees')],
            'employees.*.ref' => ['required', 'string', 'max:64', 'distinct'],
            'employees.*.name' => ['nullable', 'string', 'max:160'],
            'employees.*.gross_cents' => ['required', 'integer', 'min:0'],
            'employees.*.paye_cents' => ['required', 'integer', 'min:0'],
            'employees.*.uif_employee_cents' => ['required', 'integer', 'min:0'],
            'employees.*.uif_employer_cents' => ['required', 'integer', 'min:0'],
            'employees.*.sdl_cents' => ['required', 'integer', 'min:0'],
            'employees.*.eti_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw new ImportRejected('The payload is not valid.', 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $t = $data['totals'];

        $age = abs($now->diffInMinutes(CarbonImmutable::parse($data['issued_at'])));
        if ($age > (int) config('tax.payroll_import.max_age_minutes')) {
            throw new ImportRejected('The payload is too old or dated in the future (replay protection).', 422);
        }
        if ($t['paye_cents'] + $t['uif_employee_cents'] > $t['gross_pay_cents']) {
            throw new ImportRejected('PAYE and employee UIF cannot be more than gross pay.', 422);
        }

        if (! empty($data['employees'])) {
            $sum = fn (string $f) => array_sum(array_map(fn ($e) => (int) ($e[$f] ?? 0), $data['employees']));
            $pairs = ['gross_cents' => 'gross_pay_cents', 'paye_cents' => 'paye_cents', 'uif_employee_cents' => 'uif_employee_cents', 'uif_employer_cents' => 'uif_employer_cents', 'sdl_cents' => 'sdl_cents', 'eti_cents' => 'eti_claimed_cents'];
            foreach ($pairs as $line => $total) {
                if ($sum($line) !== (int) $t[$total]) {
                    throw new ImportRejected("The employee lines do not add up to the totals ({$total}).", 422);
                }
            }
            if (count($data['employees']) !== (int) $t['employee_count']) {
                throw new ImportRejected('The employee count does not match the number of employee lines.', 422);
            }
        }

        return $data;
    }

    private function fill(TaxPayrollRun $run, array $data, string $hash): void
    {
        $t = $data['totals'];
        $run->fill([
            'source' => 'hr_import', 'hr_run_id' => $data['hr_run_id'], 'gross_pay_cents' => $t['gross_pay_cents'], 'paye_cents' => $t['paye_cents'],
            'uif_employee_cents' => $t['uif_employee_cents'], 'uif_employer_cents' => $t['uif_employer_cents'], 'sdl_cents' => $t['sdl_cents'],
            'eti_claimed_cents' => $t['eti_claimed_cents'], 'employee_count' => $t['employee_count'], 'payload_hash' => $hash, 'imported_at' => now(),
        ]);
    }

    private function replaceLines(LedgerContext $ctx, TaxPayrollRun $run, array $data): void
    {
        $this->runs->replaceLines($ctx, $run, $data['employees'] ?? []);
    }

    private function audit(LedgerContext $ctx, TaxPayrollRun $run, string $action): void
    {
        FinanceAudit::record($ctx->ownerAttributes(), $action, 'tax_payroll', (int) $run->id, 'Payroll tax summary '.$run->period_month->format('Y-m').' (Dot.HR)', [
            'gross_pay_cents' => ['after' => $run->gross_pay_cents], 'paye_cents' => ['after' => $run->paye_cents],
        ], 'hr_run_id '.$run->hr_run_id, null, null);
    }
}
