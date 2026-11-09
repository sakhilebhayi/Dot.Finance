<?php

namespace App\Http\Controllers\Sales;

use App\Models\Accounting\GlAccount;
use App\Models\Sales\SalesRecurringTemplate;
use App\Models\Shared\Currency;
use App\Models\Shared\Party;
use App\Services\Sales\RecurringInvoices;
use App\Services\Sales\SalesProfiles;
use App\Services\Shared\TaxRuleBook;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Repeating invoices: a template and a schedule. One invoice per template and period, however often the command runs. */
class RecurringController extends SalesController
{
    public function index(): View
    {
        $this->allow('issue-invoice');

        return view('sales.recurring.index', ['templates' => SalesRecurringTemplate::query()->with('party')->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->orderBy('next_run_date')->get()]);
    }

    public function create(): View
    {
        $this->allow('issue-invoice');

        return $this->form(new SalesRecurringTemplate(['frequency' => 'monthly', 'every' => 1, 'mode' => 'draft', 'terms_days' => 30, 'start_date' => now()->toDateString(), 'lines' => [['description' => '', 'quantity' => '1']]]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow('issue-invoice');
        [$data, $lines] = $this->validated($request);

        $template = SalesRecurringTemplate::query()->create($this->ledger()->ownerAttributes() + $data + ['lines' => $lines, 'next_run_date' => $data['start_date'], 'status' => 'active', 'created_by' => $request->user()->id]);

        return redirect()->route('sales.recurring.index')->with('status', 'Saved. The first '.($template->mode === 'issue' ? 'invoice is issued' : 'draft is made').' on '.$template->next_run_date->format('j M Y').'.');
    }

    public function edit(SalesRecurringTemplate $template): View
    {
        $this->allow('issue-invoice');

        return $this->form($template);
    }

    public function update(Request $request, SalesRecurringTemplate $template): RedirectResponse
    {
        $this->allow('issue-invoice');
        [$data, $lines] = $this->validated($request);
        unset($data['start_date']);   // the anchor of the schedule does not move once it has started

        $template->update($data + ['lines' => $lines]);

        return redirect()->route('sales.recurring.index')->with('status', 'Saved.');
    }

    public function toggle(Request $request, SalesRecurringTemplate $template): RedirectResponse
    {
        $this->allow('issue-invoice');
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused', 'ended'])]]);
        abort_if($template->status === 'ended' && $data['status'] !== 'ended', 422);

        $template->update(['status' => $data['status']]);

        return back()->with('status', ['active' => 'Resumed.', 'paused' => 'Paused. Nothing is made while it is paused, and the missed periods are made when it resumes.', 'ended' => 'Ended.'][$data['status']]);
    }

    public function run(RecurringInvoices $recurring): RedirectResponse
    {
        $this->allow('issue-invoice');
        $counts = $recurring->runFor($this->ledger());

        return back()->with('status', 'Checked every template: '.$counts['created'].' made, '.$counts['issued'].' issued, '.$counts['existing'].' already existed.');
    }

    // ---- internals ----------------------------------------------------------------------------

    private function form(SalesRecurringTemplate $template): View
    {
        $profile = app(SalesProfiles::class)->for($this->ledger());

        return view('sales.recurring.form', [
            'template' => $template, 'customers' => $this->customers(), 'currencies' => Currency::query()->where('is_active', true)->where('minor_units', 2)->orderBy('code')->pluck('code'),
            'taxCodes' => $profile->vat_registered ? app(TaxRuleBook::class)->taxCodesOn($profile->tax_jurisdiction ?: 'ZA', CarbonImmutable::today(), 'sales') : collect(),
            'incomeAccounts' => GlAccount::query()->where('class', 'income')->where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']), 'profile' => $profile,
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $rules = collect($this->headerRules())->only(['party_id', 'currency', 'prices_include_tax', 'reference', 'notes', 'lines', 'lines.*.description', 'lines.*.unit', 'lines.*.quantity', 'lines.*.unit_price', 'lines.*.discount_percent', 'lines.*.discount_amount', 'lines.*.tax_code', 'lines.*.income_account_id'])->all();
        $data = $request->validate($rules + [
            'name' => ['required', 'string', 'max:150'], 'frequency' => ['required', Rule::in(SalesRecurringTemplate::FREQUENCIES)], 'every' => ['required', 'integer', 'min:1', 'max:52'],
            'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'mode' => ['required', Rule::in(['draft', 'issue'])],
            'terms_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);
        $party = Party::query()->customers()->active()->findOrFail($data['party_id']);
        [, $lines] = $this->documentInput($data + ['issue_date' => now()->toDateString()]);

        return [[
            'party_id' => $party->id, 'name' => $data['name'], 'frequency' => $data['frequency'], 'every' => (int) $data['every'], 'start_date' => $data['start_date'], 'end_date' => $data['end_date'] ?? null,
            'mode' => $data['mode'], 'currency' => strtoupper($data['currency'] ?? $party->currency ?? 'ZAR'), 'terms_days' => (int) $data['terms_days'],
            'prices_include_tax' => (bool) ($data['prices_include_tax'] ?? false), 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
        ], array_map(fn ($l) => collect($l)->except(['parent_line_id'])->all(), $lines)];
    }
}
