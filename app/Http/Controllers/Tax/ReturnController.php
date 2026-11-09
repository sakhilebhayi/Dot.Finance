<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Models\Tax\TaxReturn;
use App\Models\Tax\TaxReturnAdjustment;
use App\Rules\MoneyAmount;
use App\Services\Shared\Attachments;
use App\Services\Tax\Exceptions\NotReady;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\FilingAdapter;
use App\Services\Tax\TaxReturnService;
use App\Services\Tax\Workpaper;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** A VAT201 or EMP201: the page, the adjustments, preparing, recording a person's submission and payment, the working paper. */
class ReturnController extends TaxController
{
    public function show(TaxReturn $taxReturn, TaxReturnService $service, FilingAdapter $filing): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        $ctx = $this->ctx();
        $frozen = $taxReturn->figures !== null;
        $computed = $frozen ? null : $service->compute($ctx, $taxReturn);
        $live = $frozen ? $service->compute($ctx, $taxReturn) : $computed;
        $checks = $service->checks($ctx, $taxReturn, $live, $profile, false);

        return view('tax.returns.show', [
            'return' => $taxReturn,
            'profile' => $profile,
            'frozen' => $frozen,
            'boxes' => $frozen ? $taxReturn->figures['boxes'] : $computed->boxes,
            'totals' => ['output' => $frozen ? $taxReturn->output_tax_cents : $computed->outputTaxCents, 'input' => $frozen ? $taxReturn->input_tax_cents : $computed->inputTaxCents, 'payable' => $frozen ? $taxReturn->payable_cents : $computed->payableCents],
            'live' => $live,
            'reconciliation' => $frozen ? ($taxReturn->reconciliation ?? []) : ($computed->reconciliation ?? []),
            'review' => $live->reconciliation['review'] ?? [],
            'checks' => $frozen ? ($taxReturn->checks ?? $checks) : $checks,
            'liveChecks' => $checks,
            'ruleVersions' => $frozen ? ($taxReturn->rule_versions ?? []) : $computed->ruleVersions,
            'adjustments' => $taxReturn->adjustments()->get(),
            'adjustable' => \App\Services\Tax\Vat201Builder::ADJUSTABLE,
            'adjustmentKinds' => TaxReturnAdjustment::KINDS,
            'instructions' => $filing->instructions($taxReturn),
            'integrity' => $frozen ? $service->figuresIntact($taxReturn) : null,
            'settlement' => $taxReturn->kind === 'vat201' && ! $taxReturn->isDraft() ? $service->suggestSettlement($ctx, $taxReturn) : [],
            'superseded' => TaxReturn::query()->forContext($ctx)->where('kind', $taxReturn->kind)->where('period_start', $taxReturn->period_start->toDateString())->where('revision', '>', $taxReturn->revision)->exists(),
            'canPrepare' => $this->can(Capability::PrepareTaxReturn),
            'canRecord' => $this->can(Capability::RecordTaxSubmission),
            'canExport' => $this->can(Capability::ExportReports),
            'canPost' => $this->can(Capability::PostJournal),
            'today' => CarbonImmutable::today(),
        ]);
    }

    public function adjust(Request $request, TaxReturn $taxReturn, TaxReturnService $service, Attachments $attachments): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $data = $request->validate([
            'box_code' => ['required', 'string', 'max:8'],
            'kind' => ['required', Rule::in(array_keys(TaxReturnAdjustment::KINDS))],
            'direction' => ['required', Rule::in(['increase', 'decrease'])],
            'amount' => ['required', new MoneyAmount],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'file' => ['nullable', 'file', 'max:15360'],
        ]);

        $cents = Money::parse($data['amount']) * ($data['direction'] === 'decrease' ? -1 : 1);

        try {
            $attachment = $request->file('file') ? $attachments->store($request->file('file'), null, $request->user(), true, $this->ctx()) : null;
            $service->addAdjustment($this->ctx(), $taxReturn, $data['box_code'], $data['kind'], $cents, $data['reason'], $request->user(), $attachment?->id);
        } catch (TaxException|\App\Support\Shared\Exceptions\AttachmentRejected $e) {
            return back()->withInput()->withErrors(['adjustment' => $e->getMessage()]);
        }

        return back()->with('status', 'Adjustment added to field '.$data['box_code'].', with your reason on record.');
    }

    public function voidAdjustment(Request $request, TaxReturn $taxReturn, TaxReturnAdjustment $adjustment, TaxReturnService $service): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        abort_unless((int) $adjustment->tax_return_id === (int) $taxReturn->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        try {
            $service->voidAdjustment($this->ctx(), $taxReturn, $adjustment, $data['reason'], $request->user());
        } catch (TaxException $e) {
            return back()->withErrors(['adjustment' => $e->getMessage()]);
        }

        return back()->with('status', 'Adjustment voided. It stays on record with your reason.');
    }

    public function prepare(Request $request, TaxReturn $taxReturn, TaxReturnService $service): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $request->validate(['acknowledge_payments_basis' => ['nullable', 'boolean']]);

        try {
            $service->prepare($this->ctx(), $taxReturn, $this->profile(), $request->user(), $request->boolean('acknowledge_payments_basis'));
        } catch (NotReady $e) {
            return back()->withErrors(['prepare' => $e->reasons()]);
        } catch (TaxException $e) {
            return back()->withErrors(['prepare' => $e->getMessage()]);
        }

        return redirect()->route('tax.returns.show', $taxReturn)->with('status', 'Prepared, not submitted. The figures are frozen. File them yourself on SARS eFiling, then record the SARS reference here.');
    }

    public function withdraw(Request $request, TaxReturn $taxReturn, TaxReturnService $service): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        try {
            $service->withdraw($this->ctx(), $taxReturn, $data['reason'], $request->user());
        } catch (TaxException $e) {
            return back()->withErrors(['prepare' => $e->getMessage()]);
        }

        return back()->with('status', 'Preparation withdrawn. The return is a draft again.');
    }

    public function submission(Request $request, TaxReturn $taxReturn, TaxReturnService $service, Attachments $attachments): RedirectResponse
    {
        $this->require(Capability::RecordTaxSubmission);
        $data = $request->validate([
            'sars_reference' => ['required', 'string', 'max:60'],
            'submitted_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
            'proof' => ['nullable', 'file', 'max:15360'],
        ]);

        try {
            $proof = $request->file('proof') ? $attachments->store($request->file('proof'), null, $request->user(), true, $this->ctx()) : null;
            $service->recordSubmission($this->ctx(), $taxReturn, $data['sars_reference'], CarbonImmutable::parse($data['submitted_on']), $request->user(), $proof?->id, $data['note'] ?? null);
        } catch (TaxException|\App\Support\Shared\Exceptions\AttachmentRejected $e) {
            return back()->withInput()->withErrors(['submission' => $e->getMessage()]);
        }

        return redirect()->route('tax.returns.show', $taxReturn)->with('status', 'Recorded. This return is now frozen: its figures, period and SARS reference cannot change. Record the payment when you have made it.');
    }

    public function payment(Request $request, TaxReturn $taxReturn, TaxReturnService $service, Attachments $attachments): RedirectResponse
    {
        $this->require(Capability::RecordTaxSubmission);
        $data = $request->validate([
            'amount' => ['required', new MoneyAmount],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:60'],
            'proof' => ['nullable', 'file', 'max:15360'],
        ]);

        try {
            $proof = $request->file('proof') ? $attachments->store($request->file('proof'), null, $request->user(), true, $this->ctx()) : null;
            $service->recordPayment($this->ctx(), $taxReturn, Money::parse($data['amount']), CarbonImmutable::parse($data['paid_on']), $data['reference'] ?? null, $request->user(), $proof?->id);
        } catch (TaxException|\App\Support\Shared\Exceptions\AttachmentRejected $e) {
            return back()->withInput()->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->route('tax.returns.show', $taxReturn)->with('status', 'Payment recorded. Dot.Finance did not make this payment: it only keeps your record of it.');
    }

    public function revision(Request $request, TaxReturn $taxReturn, TaxReturnService $service): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);

        try {
            $revision = $service->startRevision($this->ctx(), $taxReturn, $request->user());
        } catch (TaxException $e) {
            return back()->withErrors(['prepare' => $e->getMessage()]);
        }

        return redirect()->route('tax.returns.show', $revision)->with('status', 'A revision was started as a draft. The submitted return stays exactly as it was.');
    }

    public function settlement(Request $request, TaxReturn $taxReturn, TaxReturnService $service): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $this->require(Capability::PostJournal);

        try {
            $entry = $service->postSettlement($this->ctx(), $taxReturn, $request->user());
        } catch (TaxException|\App\Services\Accounting\Exceptions\AccountingException $e) {
            return back()->withErrors(['settlement' => $e->getMessage()]);
        }

        return back()->with('status', 'Posted as '.$entry->reference().': output and input VAT for the period moved to VAT control.');
    }

    public function workpaper(TaxReturn $taxReturn, Workpaper $workpapers): View
    {
        $this->require(Capability::ExportReports);
        $taxReturn->forceFill(['export_count' => $taxReturn->export_count + 1, 'last_exported_at' => now()])->save();

        return view('tax.returns.workpaper', ['paper' => $workpapers->build($this->ctx(), $taxReturn)]);
    }

    public function csv(TaxReturn $taxReturn, Workpaper $workpapers): Response
    {
        $this->require(Capability::ExportReports);
        $taxReturn->forceFill(['export_count' => $taxReturn->export_count + 1, 'last_exported_at' => now()])->save();
        $paper = $workpapers->build($this->ctx(), $taxReturn);

        return response($workpapers->csv($paper), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$taxReturn->kind.'-'.$taxReturn->period_start->format('Y-m').'-rev'.$taxReturn->revision.'-prepared-not-submitted.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
