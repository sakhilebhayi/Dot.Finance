<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Models\Tax\TaxPayrollRun;
use App\Rules\MoneyAmount;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\PayrollJournal;
use App\Services\Tax\PayrollRuns;
use App\Services\Tax\PayrollTaxCalculator;
use App\Services\Tax\TaxRules;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Payroll tax summaries. Totals are visible to anyone who can read tax; per-employee detail needs view-payroll-detail. */
class PayrollController extends TaxController
{
    public function index(): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        return view('tax.payroll.index', [
            'profile' => $profile,
            'runs' => TaxPayrollRun::query()->forContext($this->ctx())->orderByDesc('period_month')->limit(36)->get(),
            'canPrepare' => $this->can(Capability::PrepareTaxReturn),
            'month' => CarbonImmutable::today()->subMonth()->startOfMonth(),
        ]);
    }

    public function store(Request $request, PayrollRuns $runs, PayrollTaxCalculator $calculator, TaxRules $rules): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'gross_pay' => ['required', new MoneyAmount(allowZero: true)],
            'paye' => ['required', new MoneyAmount(allowZero: true)],
            'uif_employee' => ['nullable', new MoneyAmount(allowZero: true)],
            'uif_employer' => ['nullable', new MoneyAmount(allowZero: true)],
            'sdl' => ['nullable', new MoneyAmount(allowZero: true)],
            'eti' => ['nullable', new MoneyAmount(allowZero: true)],
            'employee_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'])->startOfMonth();
        if ($month->gt(CarbonImmutable::today()->startOfMonth())) {
            return back()->withInput()->withErrors(['month' => 'A payroll summary is for a month that has started.']);
        }

        $gross = Money::parse($data['gross_pay']);
        $session = $rules->session($this->ctx());
        $defaulted = [];
        $uif = $calculator->uif($session, $gross, $month);
        $uifEe = $this->given($data, 'uif_employee') ?? tap($uif['employee_cents'], function () use (&$defaulted) { $defaulted[] = 'employee UIF'; });
        $uifEr = $this->given($data, 'uif_employer') ?? tap($uif['employer_cents'], function () use (&$defaulted) { $defaulted[] = 'employer UIF'; });
        $sdl = $this->given($data, 'sdl') ?? tap($calculator->sdl($session, $gross, $gross * 12, $month)['cents'], function () use (&$defaulted) { $defaulted[] = 'SDL'; });

        try {
            $runs->capture($this->ctx(), $month, [
                'gross_pay_cents' => $gross, 'paye_cents' => Money::parse($data['paye']), 'uif_employee_cents' => $uifEe, 'uif_employer_cents' => $uifEr,
                'sdl_cents' => $sdl, 'eti_claimed_cents' => $this->given($data, 'eti') ?? 0, 'employee_count' => (int) $data['employee_count'],
                'notes' => $data['notes'] ?? null,
            ], $request->user());
        } catch (TaxException $e) {
            return back()->withInput()->withErrors(['payroll' => $e->getMessage()]);
        }

        $note = $defaulted === [] ? '' : ' Calculated from gross pay because you left them blank: '.implode(', ', $defaulted).'. The UIF ceiling applies per employee, so check them against your payroll.';

        return redirect()->route('tax.payroll.index')->with('status', 'Payroll summary for '.$month->format('F Y').' saved as a draft.'.$note);
    }

    public function show(TaxPayrollRun $taxPayrollRun, PayrollJournal $journal): View
    {
        $this->access();
        $canDetail = $this->can(Capability::ViewPayrollDetail);

        return view('tax.payroll.show', [
            'run' => $taxPayrollRun,
            'lines' => $canDetail ? $taxPayrollRun->lines()->orderBy('employee_ref')->get() : collect(),
            'lineCount' => $taxPayrollRun->lines()->count(),
            'canDetail' => $canDetail,
            'suggested' => $journal->suggest($taxPayrollRun),
            'canPost' => $this->can(Capability::PrepareTaxReturn) && $this->can(Capability::PostJournal),
        ]);
    }

    public function post(Request $request, TaxPayrollRun $taxPayrollRun, PayrollJournal $journal): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $this->require(Capability::PostJournal);
        $request->validate(['confirm' => ['accepted']]);

        try {
            $entry = $journal->post($this->ctx(), $taxPayrollRun, $request->user());
        } catch (TaxException|AccountingException $e) {
            return back()->withErrors(['post' => $e->getMessage()]);
        }

        return redirect()->route('tax.payroll.show', $taxPayrollRun)->with('status', 'Posted as '.$entry->reference().'. This payroll is now locked: it is never overwritten.');
    }

    private function given(array $data, string $key): ?int
    {
        return isset($data[$key]) && $data[$key] !== '' ? Money::parse($data[$key]) : null;
    }
}
