<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Capability;
use App\Models\Purchases\Bill;
use App\Models\Purchases\RecurringBill;
use App\Models\Shared\Party;
use App\Services\Purchases\BillCalculator;
use App\Services\Purchases\PurchaseLedger;
use App\Services\Purchases\PurchasesException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Recurring bills: a schedule that makes DRAFT bills. Nothing is posted by a schedule. */
class RecurringBillController extends PurchasesController
{
    public function index(): View
    {
        $this->authorize('viewAny', RecurringBill::class);

        return view('purchases.recurring.index', ['schedules' => RecurringBill::query()->with('party')->orderBy('status')->orderBy('next_run_on')->get(), 'canManage' => $this->gate(Capability::EnterBill)]);
    }

    public function create(): View
    {
        $this->requireCapability(Capability::EnterBill);

        return view('purchases.recurring.create', ['suppliers' => $this->suppliers(), 'accounts' => $this->accounts(), 'taxCodes' => $this->taxCodes(), 'baseCurrency' => app(PurchaseLedger::class)->baseCurrencyOrDefault($this->ledger())]);
    }

    public function store(Request $request, BillCalculator $calculator): RedirectResponse
    {
        $this->requireCapability(Capability::EnterBill);

        $v = $request->validate([
            'party_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120'], 'frequency' => ['required', Rule::in(RecurringBill::FREQUENCIES)],
            'interval_count' => ['required', 'integer', 'min:1', 'max:52'], 'start_on' => ['required', 'date_format:Y-m-d'], 'end_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_on'],
            'days_until_due' => ['required', 'integer', 'min:0', 'max:365'], 'tax_inclusive' => ['nullable', 'boolean'], 'document_type' => ['nullable', Rule::in(Bill::DOCUMENT_TYPES)],
        ] + $this->lineRules());

        $party = Party::query()->suppliers()->where('status', 'active')->find($v['party_id']);
        if ($party === null) {
            return back()->withInput()->withErrors(['party_id' => 'Choose a supplier from this ledger.']);
        }

        $lines = $this->lineRows($request);

        try {
            // prove the template is a valid bill today, so a bad template fails now and not at 03:10 some night
            $calculator->compute($this->ledger(), $lines, ['date' => CarbonImmutable::parse($v['start_on']), 'currency' => $party->currency ?? app(PurchaseLedger::class)->baseCurrencyOrDefault($this->ledger()), 'tax_inclusive' => (bool) ($v['tax_inclusive'] ?? false), 'document_type' => $v['document_type'] ?? 'unknown']);
        } catch (PurchasesException $e) {
            return back()->withInput()->withErrors(['purchases' => $e->getMessage()]);
        }

        RecurringBill::query()->create($this->ledger()->ownerAttributes() + [
            'party_id' => $party->id, 'name' => $v['name'], 'frequency' => $v['frequency'], 'interval_count' => $v['interval_count'], 'start_on' => $v['start_on'], 'next_run_on' => $v['start_on'],
            'end_on' => $v['end_on'] ?? null, 'status' => 'active', 'days_until_due' => $v['days_until_due'], 'currency' => $party->currency ?? app(PurchaseLedger::class)->baseCurrencyOrDefault($this->ledger()),
            'tax_inclusive' => (bool) ($v['tax_inclusive'] ?? false), 'document_type' => $v['document_type'] ?? 'unknown', 'template' => $lines, 'created_by' => $request->user()->id,
        ]);

        return redirect()->route('purchases.recurring.index')->with('status', 'Saved. A draft bill will be made on each due date; you review and post it.');
    }

    public function pause(RecurringBill $recurring): RedirectResponse
    {
        return $this->setStatus($recurring, 'paused', 'Paused.');
    }

    public function resume(RecurringBill $recurring): RedirectResponse
    {
        return $this->setStatus($recurring, 'active', 'Resumed.');
    }

    public function end(RecurringBill $recurring): RedirectResponse
    {
        return $this->setStatus($recurring, 'ended', 'Ended. Bills already made are untouched.');
    }

    private function setStatus(RecurringBill $recurring, string $status, string $message): RedirectResponse
    {
        $this->requireCapability(Capability::EnterBill);
        $recurring->update(['status' => $status]);

        return redirect()->route('purchases.recurring.index')->with('status', $message);
    }
}
