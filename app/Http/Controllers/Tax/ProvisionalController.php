<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Models\Tax\TaxProvisionalPayment;
use App\Rules\MoneyAmount;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\IncomeTaxEstimator;
use App\Services\Tax\ProvisionalTaxPlanner;
use App\Services\Tax\TaxRules;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProvisionalController extends TaxController
{
    public function index(Request $request, ProvisionalTaxPlanner $planner, IncomeTaxEstimator $estimator, TaxRules $rules): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }
        $request->validate(['year_end' => ['nullable', 'date_format:Y-m-d']]);

        $today = CarbonImmutable::today();
        $yearEnd = $estimator->taxYearEnd($profile, $request->filled('year_end') ? CarbonImmutable::parse($request->query('year_end')) : $today);
        $applies = $profile->tax_regime !== 'turnover_tax' && in_array($profile->legal_entity, ['company', 'close_corporation', 'sole_proprietor', 'trust'], true);

        $schedule = $applies ? $planner->schedule($this->ctx(), $profile, $yearEnd) : [];
        $suggestion = null;
        $penalty = null;
        if ($applies) {
            try {
                $estimate = $estimator->estimate($this->ctx(), $profile, $yearEnd, $today);
                $suggestion = $estimate;

                $second = collect($schedule)->firstWhere('period', 2)['row'] ?? null;
                if ($second?->estimate_taxable_income_cents !== null) {
                    $session = $rules->session($this->ctx());
                    $paid = (int) collect($schedule)->sum('paid_cents');
                    $basis = $second->basic_amount_cents ?? 0;
                    $penalty = $planner->underestimationPenalty($session, (int) $second->estimate_taxable_income_cents, $estimate->taxableIncomeCents, (int) $basis, $paid, $planner->taxFunction($this->ctx(), $profile, $yearEnd), $yearEnd);
                    $penalty['actual_is_final'] = $estimate->yearComplete;
                }
            } catch (\Throwable $e) {
                if (! $e instanceof TaxException && ! $e instanceof \App\Support\Shared\Exceptions\NoValidTaxRule) {
                    throw $e;
                }
            }
        }

        return view('tax.provisional.index', [
            'profile' => $profile, 'applies' => $applies, 'schedule' => $schedule, 'yearEnd' => $yearEnd, 'estimate' => $suggestion, 'penalty' => $penalty,
            'canPrepare' => $this->can(Capability::PrepareTaxReturn), 'canRecord' => $this->can(Capability::RecordTaxSubmission), 'today' => $today,
        ]);
    }

    public function estimate(Request $request, ProvisionalTaxPlanner $planner): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }
        $data = $request->validate([
            'year_end' => ['required', 'date_format:Y-m-d'], 'period' => ['required', 'integer', 'in:1,2,3'],
            'estimate' => ['required', new MoneyAmount(allowZero: true)], 'basic_amount' => ['nullable', new MoneyAmount(allowZero: true)],
            'basic_amount_year_end' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $planner->saveEstimate($this->ctx(), $profile, CarbonImmutable::parse($data['year_end']), (int) $data['period'], Money::parse($data['estimate']),
                isset($data['basic_amount']) ? Money::parse($data['basic_amount']) : null, isset($data['basic_amount_year_end']) ? CarbonImmutable::parse($data['basic_amount_year_end']) : null, $request->user());
        } catch (TaxException|\App\Support\Shared\Exceptions\NoValidTaxRule $e) {
            return back()->withInput()->withErrors(['estimate' => $e->getMessage()]);
        }

        return redirect()->route('tax.provisional.index', ['year_end' => $data['year_end']])->with('status', 'Your estimate is saved. The amounts below are arithmetic on your estimate, not an assessment.');
    }

    public function payment(Request $request, TaxProvisionalPayment $payment, ProvisionalTaxPlanner $planner): RedirectResponse
    {
        $this->require(Capability::RecordTaxSubmission);
        $data = $request->validate(['amount' => ['required', new MoneyAmount], 'paid_on' => ['required', 'date_format:Y-m-d'], 'reference' => ['nullable', 'string', 'max:60']]);

        try {
            $planner->recordPayment($this->ctx(), $payment, Money::parse($data['amount']), CarbonImmutable::parse($data['paid_on']), $data['reference'] ?? null, $request->user());
        } catch (TaxException $e) {
            return back()->withInput()->withErrors(['payment' => $e->getMessage()]);
        }

        return back()->with('status', 'Payment recorded. Dot.Finance did not make it: this is your record.');
    }
}
