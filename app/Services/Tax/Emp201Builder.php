<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxPayrollRun;
use App\Services\Tax\Dto\ComputedReturn;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Prepares an EMP201 (PAYE, SDL, UIF and the employment tax incentive) from the month's payroll tax summary, and
 * reconciles it to the payroll liabilities the payroll journal raised in the books. Sensitive per-employee detail is
 * never copied into the return: the boxes are totals.
 */
final class Emp201Builder
{
    public function __construct(
        private readonly TaxRules $rules,
        private readonly GlTaxLines $lines,
        private readonly PayrollTaxCalculator $calculator,
    ) {}

    public function build(LedgerContext $ctx, CarbonImmutable $from, CarbonImmutable $to): ComputedReturn
    {
        $rules = $this->rules->session($ctx);
        $rules->rule('emp201.due_date', $to); // the layout rests on this rule being on record

        $run = TaxPayrollRun::query()->forContext($ctx)->where('period_month', $from->toDateString())->first();
        $issues = [];
        $paye = $sdl = $uifEe = $uifEr = $eti = 0;
        $contributor = null;

        if ($run === null) {
            $issues[] = ['code' => 'no_payroll_summary', 'severity' => 'error', 'journal_line_id' => null, 'entry_id' => null, 'entry_number' => null, 'date' => $from->toDateString(), 'amount_cents' => 0,
                'message' => 'No payroll tax summary is recorded for '.$from->format('F Y').'.', 'hint' => 'Record it by hand or import it from Dot.HR. If nobody was paid this month, record a summary of zeros.'];
        } else {
            $paye = $run->paye_cents;
            $sdl = $run->sdl_cents;
            $uifEe = $run->uif_employee_cents;
            $uifEr = $run->uif_employer_cents;
            $eti = $run->eti_claimed_cents;
            $contributor = [['type' => 'run', 'id' => $run->id, 'entry_id' => $run->journal_entry_id, 'entry_number' => null, 'date' => $from->toDateString(),
                'description' => 'Payroll tax summary for '.$from->format('F Y').' ('.($run->source === 'hr_import' ? 'imported from Dot.HR' : 'entered by hand').')', 'amount_cents' => 0, 'note' => $run->employee_count.' employee(s)']];
            array_push($issues, ...$this->checkRun($ctx, $run, $rules, $from));
        }

        $boxLine = fn (string $code, string $label, int $cents, string $kind = 'input', ?string $formula = null) => [
            'code' => $code, 'label' => $label, 'kind' => $kind, 'amount_cents' => $cents,
            'contributors' => $contributor === null || $kind === 'calc' ? [] : [array_merge($contributor[0], ['amount_cents' => $cents])], 'formula' => $formula, 'note' => null,
        ];

        $uif = $uifEe + $uifEr;
        $liability = $paye + $sdl + $uif;
        $boxes = [
            $boxLine('PAYE', 'PAYE (employees\' tax) withheld', $paye),
            $boxLine('SDL', 'Skills development levy (SDL)', $sdl),
            $boxLine('UIF', 'Unemployment insurance (UIF): employee and employer', $uif),
            $boxLine('TOTAL', 'Total PAYE, SDL and UIF', $liability, 'calc', 'PAYE + SDL + UIF'),
            $boxLine('ETI', 'Employment tax incentive (ETI) claimed', $eti),
            $boxLine('PAYABLE', 'Payable to SARS (total less ETI claimed)', $liability - $eti, 'calc', 'TOTAL - ETI'),
        ];

        $ret = new ComputedReturn('emp201', $from->toDateString(), $to->toDateString(), $boxes, $liability, $eti, $liability - $eti, $issues, $rules->versions(), [], [
            'The EMP201 amounts are the monthly payroll tax summary. Penalties and interest are not computed here.',
        ]);
        $ret->reconciliation = $this->reconcile($ctx, $run, $from, $to);

        return $ret;
    }

    /** @return list<array<string, mixed>> */
    private function checkRun(LedgerContext $ctx, TaxPayrollRun $run, RuleSession $rules, CarbonImmutable $month): array
    {
        $issues = [];
        $mk = fn (string $code, string $severity, string $message, ?string $hint = null) => [
            'code' => $code, 'severity' => $severity, 'journal_line_id' => null, 'entry_id' => null, 'entry_number' => null,
            'date' => $month->toDateString(), 'amount_cents' => 0, 'message' => $message, 'hint' => $hint,
        ];

        if ($run->paye_cents > $run->gross_pay_cents) {
            $issues[] = $mk('paye_over_gross', 'error', 'PAYE is more than gross pay.');
        }

        $lines = $run->lines()->get();
        if ($lines->isNotEmpty()) {
            $sums = ['gross' => $lines->sum('gross_cents'), 'paye' => $lines->sum('paye_cents'), 'uif_ee' => $lines->sum('uif_employee_cents'), 'uif_er' => $lines->sum('uif_employer_cents'), 'sdl' => $lines->sum('sdl_cents'), 'eti' => $lines->sum('eti_cents')];
            $totals = ['gross' => $run->gross_pay_cents, 'paye' => $run->paye_cents, 'uif_ee' => $run->uif_employee_cents, 'uif_er' => $run->uif_employer_cents, 'sdl' => $run->sdl_cents, 'eti' => $run->eti_claimed_cents];
            foreach ($sums as $k => $v) {
                if ((int) $v !== (int) $totals[$k]) {
                    $issues[] = $mk('lines_do_not_add_up', 'error', "The employee lines add up to ".Money::format((int) $v)." for {$k} but the summary says ".Money::format((int) $totals[$k]).'.');
                }
            }
            foreach ($lines as $l) {
                $uif = $this->calculator->uif($rules, $l->gross_cents, $month);
                if ($uif['employee_cents'] !== $l->uif_employee_cents || $uif['employer_cents'] !== $l->uif_employer_cents) {
                    $issues[] = $mk('uif_differs_from_rule', 'warning', "UIF for employee reference {$l->employee_ref} differs from 1 percent up to the ceiling of ".Money::format($uif['ceiling_cents']).' (expected '.Money::format($uif['employee_cents']).' each).', 'Check the ceiling was applied per employee.');
                }
            }
        } else {
            $uif = $this->calculator->uif($rules, $run->gross_pay_cents, $month);
            if ($run->uif_employee_cents > $uif['employee_cents']) {
                $issues[] = $mk('uif_over_rule', 'warning', 'Employee UIF is more than 1 percent of gross pay.');
            }
        }

        return $issues;
    }

    /** @return array<string, mixed> */
    private function reconcile(LedgerContext $ctx, ?TaxPayrollRun $run, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $gl = $this->lines->payrollLiabilities($ctx, $from->toDateString(), $to->toDateString());
        $glPaye = $gl['paye_payable'];
        $glUif = $gl['uif_payable'];
        $glSdl = $gl['sdl_payable'];

        $runPayeNet = $run ? $run->paye_cents - $run->eti_claimed_cents : 0;
        $rows = [
            ['label' => 'PAYE (less ETI claimed)', 'summary_cents' => $runPayeNet, 'gl_cents' => $glPaye],
            ['label' => 'UIF (employee and employer)', 'summary_cents' => $run ? $run->uif_employee_cents + $run->uif_employer_cents : 0, 'gl_cents' => $glUif],
            ['label' => 'SDL', 'summary_cents' => $run ? $run->sdl_cents : 0, 'gl_cents' => $glSdl],
        ];
        foreach ($rows as &$r) {
            $r['difference_cents'] = $r['summary_cents'] - $r['gl_cents'];
        }
        unset($r);

        $posted = $run?->isPosted() ?? false;
        $ties = $posted && collect($rows)->every(fn ($r) => $r['difference_cents'] === 0);

        return [
            'rows' => $rows, 'posted' => $posted, 'ties' => $ties,
            'gl_total_cents' => $glPaye + $glUif + $glSdl,
            'unexplained_cents' => $posted ? array_sum(array_column($rows, 'difference_cents')) : null,
        ];
    }
}
