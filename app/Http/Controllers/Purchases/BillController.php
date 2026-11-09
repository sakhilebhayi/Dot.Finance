<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Capability;
use App\Models\Purchases\Bill;
use App\Models\Purchases\SupplierProfile;
use App\Models\Shared\Party;
use App\Services\Controls\ApprovalService;
use App\Services\Purchases\BillStatus;
use App\Services\Purchases\BillWorkflow;
use App\Services\Purchases\CategorySuggester;
use App\Services\Purchases\DuplicateDetector;
use App\Services\Purchases\DuplicateSuspected;
use App\Services\Purchases\PurchaseLedger;
use App\Services\Purchases\Settlement;
use App\Services\Shared\Attachments;
use App\Services\Shared\FxMath;
use App\Support\Money;
use App\Support\Shared\Exceptions\AttachmentRejected;
use App\Support\Shared\Exceptions\DuplicateAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Supplier bills and supplier credit notes. Every total is worked out on the server; the form only sends what was bought.
 */
class BillController extends PurchasesController
{
    public function __construct(private readonly BillWorkflow $workflow, private readonly BillStatus $status) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Bill::class);

        $v = $request->validate([
            'status' => ['nullable', Rule::in([...Bill::STATUSES, 'overdue', 'unpaid'])],
            'party_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in(['bill', 'credit_note'])],
        ]);

        $bills = Bill::query()->with('party')
            ->when($v['status'] ?? null, function ($q, $status) {
                return match ($status) {
                    'overdue' => $q->whereIn('status', ['open', 'partially_paid'])->where('due_date', '<', CarbonImmutable::today()->toDateString()),
                    'unpaid' => $q->whereIn('status', ['open', 'partially_paid']),
                    default => $q->where('status', $status),
                };
            })
            ->when($v['party_id'] ?? null, fn ($q, $id) => $q->where('party_id', $id))
            ->when($v['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when(trim((string) ($v['q'] ?? '')) !== '', function ($q) use ($v) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($v['q'])).'%';
                $q->where(fn ($w) => $w->whereRaw("LOWER(number) LIKE LOWER(?) ESCAPE '\\'", [$like])->orWhereRaw("LOWER(supplier_reference) LIKE LOWER(?) ESCAPE '\\'", [$like]));
            })
            ->orderByDesc('bill_date')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('purchases.bills.index', ['bills' => $bills, 'filters' => $v + ['status' => null, 'party_id' => null, 'q' => null, 'kind' => null], 'suppliers' => $this->suppliers(), 'canCreate' => $this->gate(Capability::EnterBill)]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Bill::class);

        $partyId = (int) $request->query('party_id');
        $bill = new Bill(['kind' => 'bill', 'bill_date' => now()->toDateString(), 'currency' => app(PurchaseLedger::class)->baseCurrencyOrDefault($this->ledger()), 'party_id' => $partyId ?: null, 'tax_inclusive' => false]);

        return view('purchases.bills.form', $this->formData($bill) + ['lines' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Bill::class);
        $data = $this->validated($request);

        return $this->attempt(function () use ($request, $data) {
            $bill = $this->workflow->saveDraft($this->ledger(), $this->user($request), $data);

            return $this->afterSave($request, $bill, 'Saved as a draft.');
        });
    }

    public function show(Bill $bill, Attachments $attachments, Settlement $settlement): View
    {
        $this->authorize('view', $bill);

        $bill->load(['lines.account', 'party', 'creditedBill']);
        $approval = $bill->status === 'draft' ? null : app(ApprovalService::class)->latestFor($bill);
        $duplicates = in_array($bill->status, ['draft'], true)
            ? app(DuplicateDetector::class)->forBill($this->ledger(), $bill->party_id, $bill->supplier_reference, $bill->total_cents, $bill->currency, CarbonImmutable::parse($bill->bill_date->toDateString()), $bill->id, $bill->kind)
            : collect();

        $files = $bill->attachments()->whereNull('archived_at')->latest('id')->get()
            ->map(fn ($f) => ['file' => $f, 'url' => $attachments->url($f, inline: true), 'download' => $attachments->url($f)]);

        $credits = $bill->isCreditNote() ? collect() : $bill->creditNotes()->whereIn('status', Bill::POSTED)->get();
        $availableCredits = $bill->isPosted() && ! $bill->isCreditNote()
            ? Bill::query()->where('kind', 'credit_note')->where('party_id', $bill->party_id)->whereNull('credit_for_bill_id')->where('status', 'open')->where('currency', $bill->currency)->get()
            : collect();

        return view('purchases.bills.show', [
            'bill' => $bill, 'approval' => $approval, 'duplicates' => $duplicates, 'files' => $files, 'credits' => $credits, 'availableCredits' => $availableCredits,
            'open' => $this->status->openCents($bill), 'paid' => $bill->isCreditNote() ? 0 : $this->status->allocatedCents($bill), 'carrying' => $bill->isPosted() && ! $bill->isCreditNote() ? $settlement->carryingBase($bill) : null,
            'allocations' => $bill->isCreditNote() ? collect() : $bill->allocations()->with('payment')->whereNull('released_at')->get(),
            'baseCurrency' => app(PurchaseLedger::class)->baseCurrencyOrDefault($this->ledger()),
            'needsApproval' => $bill->status === 'draft' && ApprovalService::requiresApproval('bill', $bill->baseTotalCents(), app(PurchaseLedger::class)->baseCurrency($this->ledger()), $this->ledger()),
        ]);
    }

    public function edit(Bill $bill): View
    {
        $this->authorize('update', $bill);

        return view('purchases.bills.form', $this->formData($bill->load('lines')) + ['lines' => $bill->lines->map(fn ($l) => [
            'description' => $l->description, 'gl_account_id' => $l->gl_account_id, 'quantity' => $l->quantity(), 'unit_price' => Money::toDecimalString($l->unit_price_cents),
            'discount' => $l->discount_bp > 0 ? rtrim(rtrim(number_format($l->discount_bp / 100, 2, '.', ''), '0'), '.') : '', 'tax_code' => $l->tax_code, 'dimension' => $l->dimension,
        ])->all()]);
    }

    public function update(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('update', $bill);
        $data = $this->validated($request) + ['expected_version' => $request->integer('version') ?: null];

        return $this->attempt(function () use ($request, $bill, $data) {
            $bill = $this->workflow->saveDraft($this->ledger(), $this->user($request), $data, $bill);

            return $this->afterSave($request, $bill, 'Saved.');
        });
    }

    public function destroy(Bill $bill): RedirectResponse
    {
        $this->authorize('delete', $bill);

        return $this->attempt(function () use ($bill) {
            $this->workflow->deleteDraft($bill);

            return redirect()->route('purchases.bills.index')->with('status', 'The draft was deleted.');
        });
    }

    public function submit(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('submit', $bill);

        return $this->attempt(function () use ($request, $bill) {
            $done = $this->workflow->submit($this->user($request), $bill, ['confirm_duplicate' => $request->boolean('confirm_duplicate')]);

            return redirect()->route('purchases.bills.show', $done)->with('status', $this->outcome($done));
        });
    }

    /** Post a bill that was approved but could not be posted at the time. */
    public function post(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('submit', $bill);

        return $this->attempt(function () use ($request, $bill) {
            $done = $this->workflow->post($this->user($request), $bill);

            return redirect()->route('purchases.bills.show', $done)->with('status', 'Posted to the books as '.$done->number.'.');
        });
    }

    public function withdraw(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('submit', $bill);

        return $this->attempt(function () use ($request, $bill) {
            $this->workflow->withdraw($this->user($request), $bill);

            return redirect()->route('purchases.bills.show', $bill)->with('status', 'Taken back to draft. You can edit it now.');
        });
    }

    public function void(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('void', $bill);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $bill, $data) {
            $this->workflow->void($this->user($request), $bill, $data['reason']);

            return redirect()->route('purchases.bills.show', $bill)->with('status', 'Voided. A reversing entry was posted; the bill stays on record.');
        });
    }

    public function attach(Request $request, Bill $bill, Attachments $attachments): RedirectResponse
    {
        $this->authorize('attach', $bill);
        $request->validate(['file' => ['required', 'file', 'max:15360']]);

        try {
            $attachments->store($request->file('file'), $bill, $this->user($request), false, $this->ledger());
        } catch (DuplicateAttachment $e) {
            return $this->duplicateFile($e);
        } catch (AttachmentRejected $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', 'Document attached.');
    }

    public function createCredit(Bill $bill): View
    {
        $this->authorize('create', Bill::class);
        abort_unless($bill->isPosted() && ! $bill->isCreditNote(), 404);

        $credit = new Bill(['kind' => 'credit_note', 'bill_date' => now()->toDateString(), 'currency' => $bill->currency, 'party_id' => $bill->party_id, 'credit_for_bill_id' => $bill->id, 'tax_inclusive' => $bill->tax_inclusive, 'document_type' => 'credit_note']);
        $lines = $bill->lines->map(fn ($l) => [
            'description' => $l->description, 'gl_account_id' => $l->gl_account_id, 'quantity' => $l->quantity(), 'unit_price' => Money::toDecimalString($l->unit_price_cents),
            'discount' => $l->discount_bp > 0 ? rtrim(rtrim(number_format($l->discount_bp / 100, 2, '.', ''), '0'), '.') : '', 'tax_code' => $l->tax_code, 'dimension' => $l->dimension,
        ])->all();

        return view('purchases.bills.form', $this->formData($credit) + ['lines' => $lines, 'creditFor' => $bill]);
    }

    public function storeCredit(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('create', Bill::class);
        abort_unless($bill->isPosted() && ! $bill->isCreditNote(), 404);

        $data = $this->validated($request, credit: true);
        $data += ['kind' => 'credit_note', 'credit_for_bill_id' => $bill->id, 'party_id' => $bill->party_id, 'currency' => $bill->currency];

        return $this->attempt(function () use ($request, $data) {
            $credit = $this->workflow->saveDraft($this->ledger(), $this->user($request), $data);

            return $this->afterSave($request, $credit, 'Saved as a draft credit note.');
        });
    }

    public function applyCredit(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('submit', $bill);
        $data = $request->validate(['credit_id' => ['required', 'integer']]);
        $credit = Bill::query()->where('kind', 'credit_note')->findOrFail($data['credit_id']);

        return $this->attempt(function () use ($request, $bill, $credit) {
            $this->workflow->applyCredit($this->user($request), $credit, $bill);

            return redirect()->route('purchases.bills.show', $bill)->with('status', 'Credit applied.');
        });
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $credit = false): array
    {
        $rules = [
            'party_id' => [$credit ? 'nullable' : 'required', 'integer'],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,8})?$/'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'supplier_reference' => ['nullable', 'string', 'max:60'],
            'document_type' => ['nullable', Rule::in(Bill::DOCUMENT_TYPES)],
            'supplier_vat_number' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9 \-]*$/'],
            'credit_reason' => ['nullable', 'string', 'max:255'],
            'early_payment_discount' => ['nullable', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'early_payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'client_token' => ['nullable', 'string', 'max:60'],
        ] + $this->lineRules();

        $v = $request->validate($rules);

        $data = [
            'party_id' => $v['party_id'] ?? null, 'bill_date' => $v['bill_date'], 'due_date' => $v['due_date'] ?? null, 'currency' => $v['currency'] ?? null,
            'tax_inclusive' => (bool) ($v['tax_inclusive'] ?? false), 'supplier_reference' => $v['supplier_reference'] ?? null, 'document_type' => $v['document_type'] ?? null,
            'credit_reason' => $v['credit_reason'] ?? null, 'notes' => $v['notes'] ?? null, 'client_token' => $v['client_token'] ?? null, 'lines' => $this->lineRows($request),
        ];

        if (($v['document_type'] ?? null) === null) {
            unset($data['document_type']);
        }
        if (array_key_exists('supplier_vat_number', $v)) {
            $data['supplier_vat_number'] = $v['supplier_vat_number'];
        }
        if (! empty($v['fx_rate'])) {
            try {
                $data['fx_rate_e8'] = FxMath::parseRate($v['fx_rate']);
            } catch (InvalidArgumentException) {
                $data['fx_rate_e8'] = null;
            }
        }
        if (array_key_exists('early_payment_discount', $v)) {
            $data['early_payment_discount_bp'] = $this->percentToBp($v['early_payment_discount']);
            $data['early_payment_days'] = $v['early_payment_days'] ?? null;
        }

        return $data;
    }

    private function afterSave(Request $request, Bill $bill, string $message): RedirectResponse
    {
        if ($request->boolean('submit_now')) {
            try {
                $bill = $this->workflow->submit($this->user($request), $bill);
                $message = $this->outcome($bill);
            } catch (DuplicateSuspected $e) {
                return redirect()->route('purchases.bills.show', $bill)->with('status', 'Saved as a draft.')->withErrors(['purchases' => $e->getMessage()]);
            }
        }

        return redirect()->route('purchases.bills.show', $bill)->with('status', $message);
    }

    private function outcome(Bill $bill): string
    {
        return match ($bill->status) {
            'awaiting_approval' => 'Sent for approval. It will be posted to the books when it is approved.',
            'approved' => 'Approved. '.($bill->post_error ?: 'It will be posted shortly.'),
            'open', 'partially_paid', 'paid' => $bill->kindLabel().' posted to the books as '.$bill->number.'.',
            default => 'Saved.',
        };
    }

    /** @return array<string, mixed> */
    private function formData(Bill $bill): array
    {
        $on = $bill->bill_date ? CarbonImmutable::parse($bill->bill_date->toDateString()) : CarbonImmutable::today();
        $ledger = $this->ledger();
        $party = $bill->party_id ? Party::query()->find($bill->party_id) : null;

        return [
            'bill' => $bill, 'suppliers' => $this->suppliers(), 'accounts' => $this->accounts(), 'taxCodes' => $this->taxCodes($on), 'currencies' => $this->currencies(),
            'baseCurrency' => app(PurchaseLedger::class)->baseCurrencyOrDefault($ledger), 'token' => $bill->exists ? null : Str::uuid()->toString(),
            'suggestion' => $party ? app(CategorySuggester::class)->forSupplier($ledger, $party->id) : null,
            'profile' => $party ? SupplierProfile::query()->where('party_id', $party->id)->first() : null,
            'creditFor' => $bill->credit_for_bill_id ? Bill::query()->find($bill->credit_for_bill_id) : null,
        ];
    }
}
