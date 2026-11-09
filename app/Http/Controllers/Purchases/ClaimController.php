<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Capability;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Purchases\ExpenseClaimLine;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Services\Controls\ApprovalService;
use App\Services\Purchases\BillStatus;
use App\Services\Purchases\ClaimWorkflow;
use App\Services\Purchases\PurchaseLedger;
use App\Services\Shared\Attachments;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Expense claims: receipts someone paid for out of their own pocket, approved and then paid back as an ordinary supplier payment. */
class ClaimController extends PurchasesController
{
    public function __construct(private readonly ClaimWorkflow $workflow, private readonly BillStatus $status) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ExpenseClaim::class);

        $status = $request->validate(['status' => ['nullable', Rule::in([...\App\Models\Purchases\Bill::STATUSES, 'unpaid'])]])['status'] ?? null;
        $claims = ExpenseClaim::query()->with('claimant')
            ->when($status, fn ($q) => $status === 'unpaid' ? $q->whereIn('status', ['open', 'partially_paid']) : $q->where('status', $status))
            ->orderByDesc('claim_date')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('purchases.claims.index', ['claims' => $claims, 'status' => $status, 'canCreate' => $this->gate(Capability::RecordEntries)]);
    }

    public function create(): View
    {
        $this->requireCapability(Capability::RecordEntries);

        return view('purchases.claims.form', $this->formData(new ExpenseClaim(['claim_date' => now()->toDateString(), 'claimant_user_id' => auth()->id()]), []));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireCapability(Capability::RecordEntries);
        $data = $this->validated($request);

        return $this->attempt(function () use ($request, $data) {
            $claim = $this->workflow->saveDraft($this->ledger(), $this->user($request), $data);

            return $this->afterSave($request, $claim, 'Saved as a draft.');
        });
    }

    public function show(ExpenseClaim $claim, Attachments $attachments): View
    {
        $this->authorize('view', $claim);

        $claim->load(['lines.account', 'claimant', 'party']);
        $files = [];
        foreach ($claim->lines as $line) {
            $files[$line->id] = $line->attachments()->whereNull('archived_at')->latest('id')->get()->map(fn ($f) => ['file' => $f, 'url' => $attachments->url($f, inline: true), 'download' => $attachments->url($f)]);
        }

        return view('purchases.claims.show', [
            'claim' => $claim, 'files' => $files, 'approval' => $claim->status === 'draft' ? null : app(ApprovalService::class)->latestFor($claim),
            'open' => $this->status->openCents($claim), 'baseCurrency' => app(PurchaseLedger::class)->baseCurrencyOrDefault($this->ledger()),
        ]);
    }

    public function edit(ExpenseClaim $claim): View
    {
        $this->authorize('update', $claim);

        $lines = $claim->lines->map(fn (ExpenseClaimLine $l) => [
            'spent_on' => $l->spent_on->toDateString(), 'merchant' => $l->merchant, 'description' => $l->description, 'receipt_type' => $l->receipt_type, 'merchant_vat_number' => $l->merchant_vat_number,
            'receipt_reference' => $l->receipt_reference, 'gl_account_id' => $l->gl_account_id, 'tax_code' => $l->tax_code, 'dimension' => $l->dimension, 'gross' => Money::toDecimalString($l->gross_cents),
        ])->all();

        return view('purchases.claims.form', $this->formData($claim, $lines));
    }

    public function update(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $this->authorize('update', $claim);
        $data = $this->validated($request) + ['expected_version' => $request->integer('version') ?: null];

        return $this->attempt(function () use ($request, $claim, $data) {
            $claim = $this->workflow->saveDraft($this->ledger(), $this->user($request), $data, $claim);

            return $this->afterSave($request, $claim, 'Saved.');
        });
    }

    public function destroy(ExpenseClaim $claim): RedirectResponse
    {
        $this->authorize('delete', $claim);

        return $this->attempt(function () use ($claim) {
            $this->workflow->deleteDraft($claim);

            return redirect()->route('purchases.claims.index')->with('status', 'The draft was deleted.');
        });
    }

    public function submit(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $this->authorize('submit', $claim);

        return $this->attempt(function () use ($request, $claim) {
            $done = $this->workflow->submit($this->user($request), $claim);

            return redirect()->route('purchases.claims.show', $done)->with('status', $this->outcome($done));
        });
    }

    public function post(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $this->authorize('submit', $claim);

        return $this->attempt(function () use ($request, $claim) {
            $done = $this->workflow->post($this->user($request), $claim);

            return redirect()->route('purchases.claims.show', $done)->with('status', $this->outcome($done));
        });
    }

    public function withdraw(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $this->authorize('submit', $claim);

        return $this->attempt(function () use ($request, $claim) {
            $this->workflow->withdraw($this->user($request), $claim);

            return redirect()->route('purchases.claims.show', $claim)->with('status', 'Taken back to draft. You can edit it now.');
        });
    }

    public function void(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $this->authorize('void', $claim);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        return $this->attempt(function () use ($request, $claim, $reason) {
            $this->workflow->void($this->user($request), $claim, $reason);

            return redirect()->route('purchases.claims.show', $claim)->with('status', 'Voided. A reversing entry was posted; the claim stays on record.');
        });
    }

    public function attach(Request $request, ExpenseClaim $claim, ExpenseClaimLine $line, Attachments $attachments): RedirectResponse
    {
        $this->authorize('attach', $claim);
        abort_unless($line->expense_claim_id === $claim->id, 404);
        $request->validate(['file' => ['required', 'file', 'max:15360']]);

        try {
            $attachments->store($request->file('file'), $line, $this->user($request), false, $this->ledger());
        } catch (\App\Support\Shared\Exceptions\DuplicateAttachment $e) {
            return $this->duplicateFile($e);
        } catch (\App\Support\Shared\Exceptions\AttachmentRejected $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', 'Receipt attached.');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $v = $request->validate([
            'title' => ['required', 'string', 'max:120'], 'claim_date' => ['required', 'date_format:Y-m-d'], 'claimant_user_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:5000'],
            'client_token' => ['nullable', 'string', 'max:60'], 'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.spent_on' => ['nullable', 'date_format:Y-m-d'], 'lines.*.merchant' => ['nullable', 'string', 'max:120'], 'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.receipt_type' => ['nullable', Rule::in(ExpenseClaimLine::RECEIPT_TYPES)], 'lines.*.merchant_vat_number' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9 \-]*$/'],
            'lines.*.receipt_reference' => ['nullable', 'string', 'max:60'], 'lines.*.gl_account_id' => ['nullable', 'integer'], 'lines.*.tax_code' => ['nullable', 'string', 'max:40'],
            'lines.*.dimension' => ['nullable', 'string', 'max:60'], 'lines.*.gross' => ['nullable', new MoneyAmount(allowZero: true)],
        ]);

        $lines = [];
        foreach ($v['lines'] as $row) {
            if (trim((string) ($row['gross'] ?? '')) === '' && trim((string) ($row['merchant'] ?? '')) === '') {
                continue;
            }
            $lines[] = [
                'spent_on' => $row['spent_on'] ?? now()->toDateString(), 'merchant' => $row['merchant'] ?? '', 'description' => $row['description'] ?? '', 'receipt_type' => $row['receipt_type'] ?? 'unknown',
                'merchant_vat_number' => $row['merchant_vat_number'] ?? null, 'receipt_reference' => $row['receipt_reference'] ?? null, 'gl_account_id' => (int) ($row['gl_account_id'] ?? 0),
                'tax_code' => (string) ($row['tax_code'] ?? ''), 'dimension' => $row['dimension'] ?? null, 'gross_cents' => Money::parse((string) ($row['gross'] ?? '0')),
            ];
        }

        return ['title' => $v['title'], 'claim_date' => $v['claim_date'], 'claimant_user_id' => $v['claimant_user_id'] ?? $request->user()->id, 'notes' => $v['notes'] ?? null, 'client_token' => $v['client_token'] ?? null, 'lines' => $lines];
    }

    private function afterSave(Request $request, ExpenseClaim $claim, string $message): RedirectResponse
    {
        if ($request->boolean('submit_now')) {
            $claim = $this->workflow->submit($this->user($request), $claim);
            $message = $this->outcome($claim);
        }

        return redirect()->route('purchases.claims.show', $claim)->with('status', $message);
    }

    private function outcome(ExpenseClaim $claim): string
    {
        return match ($claim->status) {
            'awaiting_approval' => 'Sent for approval. It will be posted to the books when it is approved.',
            'approved' => 'Approved. '.($claim->post_error ?: 'It will be posted shortly.'),
            'open', 'partially_paid', 'paid' => 'Claim posted to the books as '.$claim->number.'. It can now be paid back.',
            default => 'Saved.',
        };
    }

    /** @return array<string, mixed> */
    private function formData(ExpenseClaim $claim, array $lines): array
    {
        $ledger = $this->ledger();
        $members = $ledger->isCompany() ? ($ledger->team()?->allUsers() ?? collect()) : collect([auth()->user()]);

        return [
            'claim' => $claim, 'lines' => $lines, 'accounts' => $this->accounts(), 'taxCodes' => $this->taxCodes(), 'members' => $members->unique('id')->values(),
            'token' => $claim->exists ? null : Str::uuid()->toString(), 'baseCurrency' => app(PurchaseLedger::class)->baseCurrencyOrDefault($ledger),
        ];
    }
}
