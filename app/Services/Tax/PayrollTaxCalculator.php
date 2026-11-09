<?php

namespace App\Services\Tax;

use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * UIF and SDL arithmetic from the rule book. UIF is 1 percent each for the employee and the employer on remuneration up to
 * a monthly ceiling, PER EMPLOYEE. SDL is 1 percent of total remuneration unless the employer's annual payroll is under the
 * exemption threshold. Integers only, half away from zero.
 */
final class PayrollTaxCalculator
{
    /** @return array{employee_cents: int, employer_cents: int, ceiling_cents: int, capped: bool} */
    public function uif(RuleSession $rules, int $monthlyRemunerationCents, CarbonInterface|string $on): array
    {
        $ceiling = $rules->rule('uif.ceiling_monthly_cents', $on)->cents();
        $employeeBp = $rules->rule('uif.rate_employee_bp', $on)->basisPoints();
        $employerBp = $rules->rule('uif.rate_employer_bp', $on)->basisPoints();
        $base = max(0, min($monthlyRemunerationCents, $ceiling));

        return [
            'employee_cents' => Money::mulDivRound($base, $employeeBp, 10_000),
            'employer_cents' => Money::mulDivRound($base, $employerBp, 10_000),
            'ceiling_cents' => $ceiling,
            'capped' => $monthlyRemunerationCents > $ceiling,
        ];
    }

    /**
     * SDL for a month. $annualisedPayrollCents is the employer's payroll over a year; strictly under the threshold is exempt.
     *
     * @return array{cents: int, exempt: bool, threshold_cents: int}
     */
    public function sdl(RuleSession $rules, int $monthlyRemunerationCents, int $annualisedPayrollCents, CarbonInterface|string $on): array
    {
        $threshold = $rules->rule('sdl.payroll_exemption_threshold_cents', $on)->cents();
        $bp = $rules->rule('sdl.rate_bp', $on)->basisPoints();
        $exempt = $annualisedPayrollCents < $threshold;

        return ['cents' => $exempt ? 0 : Money::mulDivRound(max(0, $monthlyRemunerationCents), $bp, 10_000), 'exempt' => $exempt, 'threshold_cents' => $threshold];
    }
}
