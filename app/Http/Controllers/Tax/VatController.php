<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxReturn;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\TaxReturnService;
use App\Services\Tax\TaxRules;
use App\Services\Tax\Vat201Builder;
use App\Services\Tax\VatPeriods;
use App\Services\Tax\VatReviewQueue;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VatController extends TaxController
{
    public function index(VatPeriods $periods, TaxRules $rules): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        $rows = [];
        if ($profile->vat_registered && $profile->vat_category) {
            $session = $rules->session($this->ctx());
            $today = CarbonImmutable::today();
            $from = $today->subMonths(14);
            if ($profile->vat_registered_on && CarbonImmutable::parse($profile->vat_registered_on->toDateString())->gt($from)) {
                $from = CarbonImmutable::parse($profile->vat_registered_on->toDateString());
            }

            foreach (array_reverse($periods->between($session, $from, $today, $profile->vat_category, $profile->financial_year_end_month)) as $p) {
                $return = TaxReturn::query()->forContext($this->ctx())->where('kind', 'vat201')->where('period_start', $p['start']->toDateString())->where('period_end', $p['end']->toDateString())->orderByDesc('revision')->first();
                $deadline = TaxDeadline::query()->forContext($this->ctx())->where('obligation', 'vat201')->where('period_start', $p['start']->toDateString())->where('period_end', $p['end']->toDateString())->first();
                $rows[] = ['period' => $p, 'label' => $periods->label($p, $profile->vat_category), 'return' => $return, 'deadline' => $deadline, 'ended' => $p['end']->lt($today)];
            }
        }

        return view('tax.vat.index', ['profile' => $profile, 'rows' => $rows, 'canPrepare' => $this->can(Capability::PrepareTaxReturn), 'today' => CarbonImmutable::today()]);
    }

    /** Create the draft return for the period that starts on the given date (the end comes from the category, not the browser). */
    public function start(Request $request, TaxReturnService $returns, VatPeriods $periods, TaxRules $rules): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }
        $data = $request->validate(['period_start' => ['required', 'date_format:Y-m-d']]);

        try {
            $p = $periods->containing($rules->session($this->ctx()), CarbonImmutable::parse($data['period_start']), $profile->vat_category ?? 'B', $profile->financial_year_end_month);
            if ($p['start']->toDateString() !== $data['period_start']) {
                throw new TaxException('That date is not the start of a VAT period for category '.$profile->vat_category.'.');
            }
            $return = $returns->start($this->ctx(), 'vat201', $p['start'], $p['end'], $periods->label($p, $profile->vat_category ?? 'B'), $profile, $request->user());
        } catch (TaxException $e) {
            return back()->withErrors(['period_start' => $e->getMessage()]);
        }

        return redirect()->route('tax.returns.show', $return);
    }

    public function review(Request $request, VatReviewQueue $queue, Vat201Builder $builder, VatPeriods $periods, TaxRules $rules): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $session = $rules->session($this->ctx());
        $today = CarbonImmutable::today();

        if ($request->filled('from') && $request->filled('to')) {
            $from = CarbonImmutable::parse($request->query('from'));
            $to = CarbonImmutable::parse($request->query('to'));
        } else {
            $p = $periods->containing($session, $today->subMonth(), $profile->vat_category ?? 'B', $profile->financial_year_end_month);
            $from = $p['start'];
            $to = $p['end'];
        }

        $computed = $builder->build($this->ctx(), $from, $to, []);
        $issues = $queue->forPeriod($this->ctx(), $from, $to, $computed);

        return view('tax.vat.review', [
            'profile' => $profile, 'from' => $from, 'to' => $to, 'issues' => $issues,
            'open' => collect($issues)->where('resolved', false)->count(),
            'canResolve' => $this->can(Capability::PrepareTaxReturn),
        ]);
    }

    public function resolve(Request $request, VatReviewQueue $queue): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $data = $request->validate([
            'journal_line_id' => ['required', 'integer'],
            'issue_code' => ['required', 'string', 'max:40', Rule::in(['untagged_vat_line', 'standard_without_vat', 'zero_rated_with_vat', 'vat_amount_mismatch', 'denied_input_claimed', 'code_not_for_sales', 'input_vat_no_supplier_vat_number', 'input_vat_no_document', 'input_vat_old', 'vat_without_base_line', 'standard_purchase_without_vat'])],
            'resolution' => ['required', Rule::in(['accepted', 'corrected', 'ignored'])],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        // the line must belong to this ledger (the ledger scope on the model makes another ledger's id a miss)
        $line = \App\Models\Accounting\JournalLine::query()->find($data['journal_line_id']);
        abort_if($line === null, 404);

        $queue->resolve($this->ctx(), $line->id, $data['issue_code'], $data['resolution'], $data['note'], $request->user());

        return back()->with('status', 'Recorded. The decision and your note are kept.');
    }
}
