<?php

namespace App\Services\Integrations\Hr;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * One pay run's totals as Dot.HR would send them (docs/integrations/hr-payroll-contract.md). Totals only: no
 * employee names, ID numbers, bank details or per-person pay leave Dot.HR in this contract (decision D-17: payroll
 * detail is for owners, finance managers and accountants only). Money is whole minor units.
 *
 * NOT CONNECTED: Dot.HR has no payroll tables and no outbound API today (read in ~/Dot/Dot.HR), so nothing sends this.
 */
final readonly class PayrollSummary
{
    /** @param array<string, int> $totals */
    public function __construct(
        public string $companyUuid,
        public string $runId,
        public string $periodStart,
        public string $periodEnd,
        public string $payDate,
        public string $currency,
        public int $employees,
        public array $totals,
    ) {}

    public const TOTALS = ['gross_cents', 'net_cents', 'paye_cents', 'uif_employee_cents', 'uif_employer_cents', 'sdl_cents', 'eti_cents'];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $rules = [
            'company_uuid' => ['required', 'uuid'],
            'run_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'pay_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'employees' => ['required', 'integer', 'min:0', 'max:1000000'],
            'totals' => ['required', 'array'],
        ];
        foreach (self::TOTALS as $key) {
            $rules["totals.{$key}"] = [$key === 'eti_cents' ? 'nullable' : 'required', 'integer', 'min:0', 'max:99999999999999'];
        }

        $v = Validator::make($data, $rules)->validate();

        // Net pay cannot exceed gross pay: a summary that says so is wrong, not just unusual.
        if ($v['totals']['net_cents'] > $v['totals']['gross_cents']) {
            throw ValidationException::withMessages(['totals.net_cents' => 'Net pay cannot be more than gross pay.']);
        }

        return new self(
            strtolower($v['company_uuid']), $v['run_id'], $v['period_start'], $v['period_end'], $v['pay_date'], $v['currency'], (int) $v['employees'],
            array_map('intval', array_intersect_key($v['totals'], array_flip(self::TOTALS))),
        );
    }
}
