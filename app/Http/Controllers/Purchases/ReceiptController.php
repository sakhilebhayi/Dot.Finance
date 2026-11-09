<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Capability;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Shared\Attachment;
use App\Services\Purchases\CategorySuggester;
use App\Services\Purchases\Extraction\ExtractorManager;
use App\Services\Purchases\Extraction\RuleBasedExtractor;
use App\Services\Purchases\PurchaseLedger;
use App\Services\Purchases\ReceiptCapture;
use App\Services\Shared\Attachments;
use App\Support\Money;
use App\Support\Shared\Exceptions\AttachmentRejected;
use App\Support\Shared\Exceptions\DuplicateAttachment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The receipts inbox. A file is uploaded and read into a draft; the person reviews it beside the document and corrects it; only then is a
 * draft bill (or a claim line) made. Reading never posts or creates anything on its own.
 */
class ReceiptController extends PurchasesController
{
    public function __construct(private readonly ReceiptCapture $capture, private readonly ExtractorManager $extractors) {}

    public function index(): View
    {
        $this->requireCapability(Capability::RecordEntries);

        $inbox = Attachment::query()->whereNull('attachable_type')->whereNull('archived_at')->whereIn('extraction_status', ['none', 'pending', 'draft', 'failed'])->latest('id')->limit(100)->get();
        $done = Attachment::query()->whereNull('archived_at')->whereIn('extraction_status', ['reviewed', 'rejected'])->latest('id')->limit(20)->get();

        return view('purchases.receipts.index', ['inbox' => $inbox, 'done' => $done, 'modelEnabled' => $this->extractors->modelEnabled(), 'capture' => $this->capture]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireCapability(Capability::RecordEntries);
        $request->validate(['file' => ['required', 'file', 'max:15360']]);

        try {
            $attachment = $this->capture->capture($this->ledger(), $this->user($request), $request->file('file'));
        } catch (DuplicateAttachment $e) {
            return $this->duplicateFile($e);
        } catch (AttachmentRejected $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('purchases.receipts.show', $attachment)->with('status', 'Uploaded. Check what was read from it against the document before you make anything from it.');
    }

    public function show(Attachment $attachment, Attachments $attachments): View
    {
        $this->openOnly($attachment);
        $this->authorize('view', $attachment);

        $latest = $this->capture->latest($attachment);
        $fields = $latest['fields'] ?? [];
        $suppliers = $this->suppliers();

        // match the supplier by VAT number, then by name
        $guess = null;
        if (! empty($fields['supplier_vat_number'])) {
            $guess = $suppliers->first(fn ($p) => $p->tax_number === $fields['supplier_vat_number']);
        }
        if ($guess === null && ! empty($fields['supplier_name'])) {
            $guess = $suppliers->first(fn ($p) => mb_strtolower($p->name) === mb_strtolower($fields['supplier_name']));
        }

        return view('purchases.receipts.show', [
            'attachment' => $attachment, 'record' => $this->capture->record($attachment), 'latest' => $latest, 'fields' => $fields, 'suppliers' => $suppliers, 'guess' => $guess,
            'url' => $attachments->url($attachment, inline: true), 'download' => $attachments->url($attachment), 'accounts' => $this->accounts(), 'taxCodes' => $this->taxCodes(),
            'suggestion' => $guess ? app(CategorySuggester::class)->forSupplier($this->ledger(), $guess->id) : null,
            'claims' => ExpenseClaim::query()->where('status', 'draft')->orderByDesc('id')->get(), 'currencies' => $this->currencies(), 'baseCurrency' => app(PurchaseLedger::class)->baseCurrencyOrDefault($this->ledger()),
            'modelEnabled' => $this->extractors->modelEnabled(), 'open' => $attachment->attachable_type === null && ! in_array($attachment->extraction_status, ['reviewed', 'rejected'], true),
        ]);
    }

    public function read(Attachment $attachment): RedirectResponse
    {
        $this->requireCapability(Capability::RecordEntries);
        $this->openOnly($attachment);
        abort_if($attachment->attachable_type !== null || $attachment->extraction_status === 'reviewed', 409);

        $this->capture->read($attachment);

        return back()->with('status', 'Read again. The earlier readings are kept.');
    }

    public function toBill(Request $request, Attachment $attachment): RedirectResponse
    {
        $this->requireCapability(Capability::EnterBill);
        $this->openOnly($attachment);

        $v = $request->validate($this->fieldRules() + ['party_id' => ['required', 'integer'], 'gl_account_id' => ['required', 'integer'], 'tax_code' => ['required', 'string', 'max:40']]);

        return $this->attempt(function () use ($request, $attachment, $v) {
            $bill = $this->capture->toBill($this->ledger(), $this->user($request), $attachment, $this->fields($v));

            return redirect()->route('purchases.bills.show', $bill)->with('status', 'A draft bill was made from the document. Check it, then submit it.');
        });
    }

    public function toClaim(Request $request, Attachment $attachment): RedirectResponse
    {
        $this->requireCapability(Capability::RecordEntries);
        $this->openOnly($attachment);

        $v = $request->validate($this->fieldRules() + ['claim_id' => ['required', 'integer'], 'gl_account_id' => ['required', 'integer'], 'tax_code' => ['required', 'string', 'max:40'], 'supplier_name' => ['required', 'string', 'max:120']]);
        $claim = ExpenseClaim::query()->where('status', 'draft')->findOrFail($v['claim_id']);

        return $this->attempt(function () use ($request, $attachment, $claim, $v) {
            $this->capture->toClaimLine($this->ledger(), $this->user($request), $attachment, $claim, $this->fields($v));

            return redirect()->route('purchases.claims.show', $claim)->with('status', 'The receipt was added to the claim.');
        });
    }

    public function reject(Request $request, Attachment $attachment): RedirectResponse
    {
        $this->requireCapability(Capability::RecordEntries);
        $this->openOnly($attachment);

        $this->capture->reject($this->user($request), $attachment);

        return redirect()->route('purchases.receipts.index')->with('status', 'Marked as not a purchase document. The file stays on record.');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** The inbox shows files that belong to no record yet, or finished ones from this inbox. Anything else is a 404. */
    private function openOnly(Attachment $attachment): void
    {
        abort_unless($attachment->attachable_type === null || in_array($attachment->extraction_status, ['reviewed', 'rejected'], true), 404);
    }

    /** @return array<string, mixed> */
    private function fieldRules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:60'], 'bill_date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'currency' => ['nullable', 'string', 'size:3'],
            'total' => ['required', new \App\Rules\MoneyAmount], 'tax' => ['nullable', new \App\Rules\MoneyAmount(allowZero: true)], 'document_type' => ['required', Rule::in(\App\Models\Purchases\Bill::DOCUMENT_TYPES)],
            'supplier_vat_number' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9 \-]*$/'], 'description' => ['nullable', 'string', 'max:255'], 'supplier_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @param array<string, mixed> $v @return array<string, mixed> */
    private function fields(array $v): array
    {
        return [
            'party_id' => $v['party_id'] ?? null, 'reference' => $v['reference'] ?? null, 'bill_date' => $v['bill_date'], 'due_date' => $v['due_date'] ?? null, 'currency' => $v['currency'] ?? null,
            'total_cents' => Money::parse((string) $v['total']), 'tax_cents' => isset($v['tax']) && $v['tax'] !== '' ? Money::parse((string) $v['tax']) : null, 'document_type' => $v['document_type'],
            'supplier_vat_number' => $v['supplier_vat_number'] ?? null, 'description' => $v['description'] ?? null, 'supplier_name' => $v['supplier_name'] ?? null,
            'gl_account_id' => (int) $v['gl_account_id'], 'tax_code' => $v['tax_code'],
        ];
    }
}
