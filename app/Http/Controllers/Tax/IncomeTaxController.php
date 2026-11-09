<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Models\Accounting\GlAccount;
use App\Models\Tax\TaxAdjustment;
use App\Models\Tax\TaxIncomeEstimate;
use App\Rules\MoneyAmount;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Shared\Attachments;
use App\Services\Tax\CsvSafe;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\IncomeTaxEstimator;
use App\Services\Tax\IncomeTaxProvision;
use App\Services\Tax\TaxBasis;
use App\Support\FinanceAudit;
use App\Support\Money;
use App\Support\Shared\Exceptions\AttachmentRejected;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IncomeTaxController extends TaxController
{
    public function index(Request $request, IncomeTaxEstimator $estimator, IncomeTaxProvision $provision): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }
        $request->validate(['year_end' => ['nullable', 'date_format:Y-m-d']]);

        $today = CarbonImmutable::today();
        $yearEnd = $estimator->taxYearEnd($profile, $request->filled('year_end') ? CarbonImmutable::parse($request->query('year_end')) : $today);

        try {
            $estimate = $estimator->estimate($this->ctx(), $profile, $yearEnd, $today);
            $error = null;
        } catch (\Throwable $e) {
            $estimate = null;
            $error = $e instanceof TaxException || $e instanceof NoValidTaxRule ? $e->getMessage() : throw $e;
        }

        $expenseAccounts = GlAccount::query()->forContext($this->ctx())->where('class', 'expense')->where('is_active', true)->where('is_postable', true)->orderBy('code')->get();
        $defaultExpense = $expenseAccounts->first(fn ($a) => str_contains(mb_strtolower($a->name), 'income tax') || str_contains(mb_strtolower($a->name), 'tax expense'));

        return view('tax.income.index', [
            'profile' => $profile, 'estimate' => $estimate, 'error' => $error, 'yearEnd' => $yearEnd,
            'saved' => TaxIncomeEstimate::query()->forContext($this->ctx())->where('tax_year_end', $yearEnd->toDateString())->orderByDesc('id')->limit(10)->get(),
            'adjustmentKinds' => TaxAdjustment::KINDS,
            'provision' => $estimate ? $provision->suggest($this->ctx(), $estimate, $defaultExpense) : null,
            'expenseAccounts' => $expenseAccounts, 'defaultExpense' => $defaultExpense,
            'canPrepare' => $this->can(Capability::PrepareTaxReturn), 'canPost' => $this->can(Capability::PostJournal), 'canExport' => $this->can(Capability::ExportReports),
            'today' => $today,
        ]);
    }

    public function adjust(Request $request, IncomeTaxEstimator $estimator, Attachments $attachments): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        $data = $request->validate([
            'tax_year_end' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', Rule::in(array_keys(TaxAdjustment::KINDS))],
            'category' => ['nullable', 'string', 'max:30'],
            'label' => ['required', 'string', 'max:160'],
            'amount' => ['nullable', new MoneyAmount(allowZero: true)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'gl_account_id' => ['nullable', 'integer'],
            'file' => ['nullable', 'file', 'max:15360'],
        ]);

        if ($data['kind'] !== 'review_only' && (! isset($data['amount']) || Money::parse($data['amount']) <= 0)) {
            return back()->withInput()->withErrors(['amount' => 'Enter the amount (more than zero).']);
        }
        // the year end must be a real year end for this entity: nothing else is accepted
        if ($estimator->taxYearEnd($profile, CarbonImmutable::parse($data['tax_year_end']))->toDateString() !== $data['tax_year_end']) {
            return back()->withInput()->withErrors(['tax_year_end' => 'That is not a year end for this business.']);
        }
        if (! empty($data['gl_account_id'])) {
            abort_if(GlAccount::query()->forContext($this->ctx())->find($data['gl_account_id']) === null, 404);
        }

        try {
            $attachment = $request->file('file') ? $attachments->store($request->file('file'), null, $request->user(), true, $this->ctx()) : null;
        } catch (AttachmentRejected $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        $adj = TaxAdjustment::query()->create($this->ctx()->ownerAttributes() + [
            'tax_year_end' => $data['tax_year_end'], 'kind' => $data['kind'], 'category' => $data['category'] ?? null, 'label' => $data['label'],
            'amount_cents' => $data['kind'] === 'review_only' ? 0 : Money::parse($data['amount']), 'reason' => $data['reason'],
            'gl_account_id' => $data['gl_account_id'] ?? null, 'attachment_id' => $attachment?->id, 'created_by_user_id' => $request->user()->id,
        ]);
        FinanceAudit::record($this->ctx()->ownerAttributes(), 'created', 'tax_adjustment', (int) $adj->id, 'Income tax adjustment: '.$adj->label, ['kind' => ['after' => $adj->kind], 'amount_cents' => ['after' => $adj->amount_cents]], 'income tax adjustment entered by a person', null, $request->user()->id);

        return redirect()->route('tax.income.index', ['year_end' => $data['tax_year_end']])->with('status', 'Recorded, with your reason. It is now in the estimate.');
    }

    public function voidAdjustment(Request $request, TaxAdjustment $taxAdjustment): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        if (! $taxAdjustment->isVoided()) {
            $taxAdjustment->forceFill(['voided_at' => now(), 'voided_by_user_id' => $request->user()->id, 'void_reason' => $data['reason']])->save();
            FinanceAudit::record($this->ctx()->ownerAttributes(), 'voided', 'tax_adjustment', (int) $taxAdjustment->id, 'Income tax adjustment: '.$taxAdjustment->label, ['amount_cents' => ['before' => $taxAdjustment->amount_cents]], $data['reason'], null, $request->user()->id);
        }

        return back()->with('status', 'Adjustment voided. It stays on record with your reason.');
    }

    public function save(Request $request, IncomeTaxEstimator $estimator): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }
        $data = $request->validate(['year_end' => ['required', 'date_format:Y-m-d'], 'label' => ['nullable', 'string', 'max:120']]);

        $yearEnd = $estimator->taxYearEnd($profile, CarbonImmutable::parse($data['year_end']));
        $estimate = $estimator->estimate($this->ctx(), $profile, $yearEnd);
        $estimator->save($this->ctx(), $profile, $estimate, $data['label'] ?: 'Estimate on '.CarbonImmutable::today()->format('j M Y'), $request->user());

        return redirect()->route('tax.income.index', ['year_end' => $yearEnd->toDateString()])->with('status', 'Estimate saved as a snapshot. An estimate is not a return.');
    }

    public function provision(Request $request, IncomeTaxEstimator $estimator, IncomeTaxProvision $provision): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $this->require(Capability::PostJournal);
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }
        $data = $request->validate(['year_end' => ['required', 'date_format:Y-m-d'], 'expense_account_id' => ['required', 'integer'], 'confirm' => ['accepted']]);

        $account = GlAccount::query()->forContext($this->ctx())->find($data['expense_account_id']);
        abort_if($account === null, 404);

        try {
            $yearEnd = $estimator->taxYearEnd($profile, CarbonImmutable::parse($data['year_end']));
            $entry = $provision->post($this->ctx(), $estimator->estimate($this->ctx(), $profile, $yearEnd), $account, $request->user());
        } catch (TaxException|AccountingException $e) {
            return back()->withErrors(['provision' => $e->getMessage()]);
        }

        return redirect()->route('tax.income.index', ['year_end' => $data['year_end']])->with('status', 'Provision posted as '.$entry->reference().'. It is an estimate; it is not a tax payment.');
    }

    public function workpaper(Request $request, IncomeTaxEstimator $estimator): View
    {
        $this->require(Capability::ExportReports);
        $profile = $this->profileOrWizard();
        abort_if($profile instanceof RedirectResponse, 404);
        $request->validate(['year_end' => ['nullable', 'date_format:Y-m-d']]);

        $yearEnd = $estimator->taxYearEnd($profile, $request->filled('year_end') ? CarbonImmutable::parse($request->query('year_end')) : CarbonImmutable::today());

        return view('tax.income.workpaper', ['estimate' => $estimator->estimate($this->ctx(), $profile, $yearEnd), 'profile' => $profile, 'generated' => CarbonImmutable::now()]);
    }

    public function csv(Request $request, IncomeTaxEstimator $estimator): Response
    {
        $this->require(Capability::ExportReports);
        $profile = $this->profileOrWizard();
        abort_if($profile instanceof RedirectResponse, 404);
        $request->validate(['year_end' => ['nullable', 'date_format:Y-m-d']]);

        $yearEnd = $estimator->taxYearEnd($profile, $request->filled('year_end') ? CarbonImmutable::parse($request->query('year_end')) : CarbonImmutable::today());
        $e = $estimator->estimate($this->ctx(), $profile, $yearEnd);

        FinanceAudit::exported($this->ctx()->ownerAttributes(), 'Income tax estimate (CSV)', ['report' => 'income-tax-estimate', 'format' => 'csv', 'tax_year_end' => $e->taxYearEnd]);
        $rows = [
            ['INCOME TAX ESTIMATE, NOT A RETURN. Prepared by a person from the books; Dot.Finance does not submit anything to SARS.'],
            ['Tax year', $e->taxYearStart, $e->taxYearEnd], ['Regime', $e->regimeLabel], ['Source of profit', $e->sourceText()], ['Year complete', $e->yearComplete ? 'yes' : 'no, year to date'],
            [], ['Line', 'Amount', 'Basis', 'Note'],
        ];
        foreach ($e->lines as $l) {
            $rows[] = [$l['label'], CsvSafe::money($l['cents']), TaxBasis::label($l['basis']), $l['note'] ?? ''];
        }
        $rows[] = [];
        $rows[] = ['Adjustments entered by a person', 'Amount', 'Kind', 'Reason'];
        foreach ($e->adjustments as $a) {
            $rows[] = [$a['label'], CsvSafe::money((int) $a['amount_cents']), $a['kind'], $a['reason']];
        }
        $rows[] = [];
        $rows[] = ['Not modelled'];
        foreach ($e->notModelled as $n) {
            $rows[] = [$n];
        }
        $rows[] = [];
        $rows[] = ['Rule versions used'];
        $rows[] = ['Version', 'Rule', 'Confidence', 'Legal status', 'Valid from', 'Source'];
        foreach ($e->ruleVersions as $v) {
            $rows[] = [$v['version'], $v['key'], $v['confidence'], $v['legal_status'], $v['valid_from'], $v['source_url']];
        }
        $rows[] = [];
        $rows[] = [TaxBasis::DISCLAIMER];

        return response(CsvSafe::render($rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="income-tax-estimate-'.$e->taxYearEnd.'-not-submitted.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
