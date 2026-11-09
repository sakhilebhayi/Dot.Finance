<?php

namespace App\Http\Controllers\Sales;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\LedgerSetting;
use App\Models\Sales\SalesApplication;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesShareLink;
use App\Models\Sales\SalesWriteOff;
use App\Models\Shared\Currency;
use App\Rules\MoneyAmount;
use App\Services\Controls\ApprovalService;
use App\Services\Sales\ApplicationEngine;
use App\Services\Sales\CreditNotes;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\DocumentLifecycle;
use App\Services\Sales\DocumentMailer;
use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Sales\SalesProfiles;
use App\Services\Sales\ShareLinks;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Sales\TaxInvoiceChecklist;
use App\Services\Sales\WriteOffs;
use App\Services\Shared\TaxRuleBook;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Quotes, pro formas, tax invoices, credit and debit notes: list, edit the draft, issue, void, send, print.
 * A document from another ledger is a 404 before any rule runs (route binding goes through the ledger scope).
 */
class SalesDocumentController extends SalesController
{
    public const GROUPS = [
        'invoices' => ['kinds' => ['tax_invoice', 'debit_note'], 'title' => 'Invoices', 'sub' => 'Tax invoices and debit notes. Money owed shows in the balance column.'],
        'quotes' => ['kinds' => ['quote', 'pro_forma'], 'title' => 'Quotes and pro formas', 'sub' => 'Offers that reach no books until one becomes an invoice.'],
        'credit-notes' => ['kinds' => ['credit_note'], 'title' => 'Credit notes', 'sub' => 'Each corrects an issued invoice. A credit that is not applied stays as customer credit.'],
    ];

    public function index(Request $request, string $group, ReceivablesLedger $receivables): View
    {
        $this->authorize('viewAny', SalesDocument::class);
        $spec = self::GROUPS[$group] ?? abort(404);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'awaiting_approval', 'open', 'issued', 'partially_paid', 'paid', 'overdue', 'void', 'accepted', 'declined', 'expired'])],
            'party' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = SalesDocument::query()->whereIn('kind', $spec['kinds'])->with('party');
        match ($filters['status'] ?? null) {
            'overdue' => $query->open()->whereNotNull('due_date')->where('due_date', '<', now()->toDateString()),
            'open' => $query->open(),
            null => null,
            default => $query->where('status', $filters['status']),
        };
        $query->when($filters['party'] ?? null, fn ($q, $id) => $q->where('party_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('issue_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('issue_date', '<=', $d))
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function ($q) use ($filters) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';
                $q->where(fn ($w) => $w->whereRaw("LOWER(number) LIKE LOWER(?) ESCAPE '\\'", [$like])->orWhereRaw("LOWER(reference) LIKE LOWER(?) ESCAPE '\\'", [$like])
                    ->orWhereHas('party', fn ($p) => $p->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '\\'", [$like])));
            });

        $documents = $query->orderByDesc('issue_date')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('sales.documents.index', [
            'group' => $group, 'spec' => $spec, 'documents' => $documents, 'filters' => $filters + ['status' => null, 'party' => null, 'q' => null, 'from' => null, 'to' => null],
            'balances' => $receivables->balances($this->ledger(), $documents->getCollection()), 'customers' => $this->customers(),
            'summary' => $group === 'invoices' ? $receivables->summary($this->ledger()) : null, 'canCreate' => request()->user()->can('create', SalesDocument::class),
        ]);
    }

    public function create(Request $request, SalesProfiles $profiles): View
    {
        $this->authorize('create', SalesDocument::class);
        $kind = $request->query('kind', 'tax_invoice');
        abort_unless(in_array($kind, ['tax_invoice', 'quote', 'pro_forma'], true), 404);

        $profile = $profiles->for($this->ledger());
        $document = new SalesDocument([
            'kind' => $kind, 'issue_date' => now()->toDateString(), 'currency' => null, 'prices_include_tax' => (bool) $profile->prices_include_tax,
            'party_id' => $request->integer('party') ?: null,
        ]);

        return $this->editor($document, collect([['description' => '', 'quantity' => '1']]), $profile);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SalesDocument::class);
        $data = $request->validate($this->headerRules() + ['kind' => $this->kindRule()]);
        abort_unless(in_array($data['kind'], ['tax_invoice', 'quote', 'pro_forma'], true), 422);
        [$header, $lines] = $this->documentInput($data);

        try {
            $document = app(DocumentDrafts::class)->create($this->ledger(), $request->user(), $header, $lines);
        } catch (SalesViolation $e) {
            return back()->withInput()->withErrors(['sales' => $e->getMessage()]);
        }

        return redirect()->route('sales.documents.show', $document)->with('status', 'Saved as a draft. It has no number yet and has not been posted: check it, then issue it.');
    }

    public function show(SalesDocument $document, ReceivablesLedger $receivables, ApplicationEngine $engine, TaxInvoiceChecklist $checklist, SalesProfiles $profiles, ShareLinks $links): View
    {
        $this->authorize('view', $document);
        $context = $this->ledger();
        $document->load(['party', 'lines', 'parent', 'children', 'events.actor', 'issuer']);

        $applied = SalesApplication::query()->where('target_document_id', $document->id)->with(['payment', 'source'])->orderBy('id')->get();
        $used = $document->isCredit() ? SalesApplication::query()->where('source_document_id', $document->id)->with('target')->orderBy('id')->get() : collect();
        $pool = $document->isReceivable() && $document->isSealed() && ! $document->isVoid() ? $engine->invoicePool($document) : null;
        $creditPool = $document->isCredit() && $document->isSealed() && ! $document->isVoid() ? $engine->creditPool($document) : null;
        $profile = $profiles->for($context);

        $unapplied = $document->isReceivable() && $pool && $pool['foreign'] > 0
            ? $receivables->unappliedCredits($context, $document->party_id)->filter(fn ($c) => $c['currency'] === $document->currency)->values()
            : collect();

        return view('sales.documents.show', [
            'document' => $document, 'applied' => $applied, 'used' => $used, 'pool' => $pool, 'creditPool' => $creditPool, 'profile' => $profile,
            'check' => $checklist->evaluate($document, $profile, $document->party), 'unapplied' => $unapplied,
            'approval' => $document->status === 'awaiting_approval' ? app(ApprovalService::class)->latestFor($document) : null,
            'links' => SalesShareLink::query()->where('document_id', $document->id)->orderByDesc('id')->limit(10)->get(),
            'writeOffs' => SalesWriteOff::query()->where('document_id', $document->id)->orderByDesc('id')->get(),
            'newLink' => session('new_link'), 'remainingCredit' => $document->isReceivable() && $document->isSealed() ? app(CreditNotes::class)->remaining($document) : null,
        ]);
    }

    public function edit(SalesDocument $document, SalesProfiles $profiles): View
    {
        $this->authorize('update', $document);
        $document->load('lines', 'parent');
        $lines = $document->lines->map(fn ($l) => [
            'description' => $l->description, 'unit' => $l->unit, 'quantity' => $l->quantity(), 'unit_price' => Money::toDecimalString((int) $l->unit_cents),
            'discount_percent' => $l->discount_bp ? rtrim(rtrim(sprintf('%d.%02d', intdiv($l->discount_bp, 100), $l->discount_bp % 100), '0'), '.') : '',
            'discount_amount' => $l->discount_cents ? Money::toDecimalString((int) $l->discount_cents) : '', 'tax_code' => $l->tax_code, 'income_account_id' => $l->income_account_id,
            'parent_line_id' => $l->parent_line_id, 'net' => $l->net_cents, 'gross' => $l->gross_cents,
        ]);

        return $this->editor($document, $lines, $profiles->for($this->ledger()));
    }

    public function update(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('update', $document);
        $data = $request->validate($this->headerRules());
        [$header, $lines] = $this->documentInput($data);

        try {
            app(DocumentDrafts::class)->update($document, $request->user(), $header, $lines, isset($data['version']) ? (int) $data['version'] : null);
        } catch (SalesViolation $e) {
            return back()->withInput()->withErrors(['sales' => $e->getMessage()]);
        }

        return redirect()->route('sales.documents.show', $document)->with('status', 'Draft saved.');
    }

    public function discard(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('discard', $document);
        $kind = $document->kind;

        return $this->attempt(fn () => app(DocumentDrafts::class)->discard($document, $request->user()), 'The draft was discarded. It never had a number, so nothing is missing from the sequence.', route($kind === 'quote' || $kind === 'pro_forma' ? 'sales.quotes.index' : ($kind === 'credit_note' ? 'sales.credit-notes.index' : 'sales.invoices.index')));
    }

    public function issue(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('issue', $document);
        $options = $request->validate(['acknowledge_credit_limit' => ['nullable', 'boolean']]);

        try {
            $result = app(DocumentIssuer::class)->issue($document, $request->user(), ['acknowledge_credit_limit' => (bool) ($options['acknowledge_credit_limit'] ?? false)]);
        } catch (SalesViolation $e) {
            return back()->withInput()->withErrors(['sales' => $e->getMessage()]);
        }

        if ($result['awaiting_approval']) {
            return back()->with('status', 'This amount needs approval first. It has no number yet and posts nothing until it is approved; it will issue itself then.');
        }

        $doc = $result['document'];

        return back()->with('status', ($result['replayed'] ? 'Already issued as ' : 'Issued as ').$doc->number.($doc->postsToBooks() ? ' and posted to the books.' : '.').($result['warnings'] ? ' '.implode(' ', $result['warnings']) : ''));
    }

    public function void(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('void', $document);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => app(DocumentLifecycle::class)->void($document, $data['reason'], $request->user()), 'Voided. The number stays on record and the posting was reversed.');
    }

    public function convert(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('correct', $document);

        try {
            $invoice = app(DocumentLifecycle::class)->convert($document, $request->user());
        } catch (SalesViolation $e) {
            return back()->withErrors(['sales' => $e->getMessage()]);
        }

        return redirect()->route('sales.documents.edit', $invoice)->with('status', 'A draft invoice was made from '.$document->number.'. Check it, then issue it.');
    }

    public function answer(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('correct', $document);
        $data = $request->validate(['answer' => ['required', Rule::in(['accepted', 'declined'])]]);

        return $this->attempt(fn () => app(DocumentLifecycle::class)->answerQuote($document, $data['answer'], $request->user()), 'Recorded as '.$data['answer'].'.');
    }

    public function credit(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('correct', $document);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $credit = app(DocumentLifecycle::class)->draftCreditNote($document, $request->user(), $data['reason'] ?? null);
        } catch (SalesViolation $e) {
            return back()->withErrors(['sales' => $e->getMessage()]);
        }

        return redirect()->route('sales.documents.edit', $credit)->with('status', 'A draft credit note for what is left on '.$document->number.'. Adjust the lines if only part is being credited, give the reason, then issue it.');
    }

    public function debit(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('correct', $document);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $debit = app(DocumentLifecycle::class)->draftDebitNote($document, $request->user(), $data['reason'] ?? null);
        } catch (SalesViolation $e) {
            return back()->withErrors(['sales' => $e->getMessage()]);
        }

        return redirect()->route('sales.documents.edit', $debit)->with('status', 'A draft debit note. Set the adjustment, give the reason, then issue it.');
    }

    public function send(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('send', $document);
        $data = $request->validate(['to' => ['required', 'email:rfc', 'max:255'], 'message' => ['nullable', 'string', 'max:2000'], 'include_link' => ['nullable', 'boolean']]);

        return $this->attempt(fn () => app(DocumentMailer::class)->send($document, $request->user(), $data['to'], $data['message'] ?? null, (bool) ($data['include_link'] ?? true)), 'Handed to the mail system for '.$data['to'].'. That is all the system can tell you: it does not know whether the message arrived.');
    }

    public function share(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('send', $document);
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:180']]);

        try {
            $made = app(ShareLinks::class)->forDocument($document, $request->user(), $data['days'] ?? null);
        } catch (SalesViolation $e) {
            return back()->withErrors(['sales' => $e->getMessage()]);
        }

        return back()->with('status', 'Link made. Copy it now: it is shown once.')->with('new_link', $made['url']);
    }

    public function applyCredit(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('recordPayment', $document);
        $data = $request->validate(['credit_id' => ['required', 'integer'], 'amount' => ['required', new MoneyAmount]]);
        $credit = SalesDocument::query()->where('kind', 'credit_note')->findOrFail($data['credit_id']);

        return $this->attempt(fn () => app(ApplicationEngine::class)->applyCredit($credit, $document, Money::parse($data['amount']), $request->user()), 'Applied credit note '.$credit->number.' to '.$document->number.'.');
    }

    public function writeOff(Request $request, SalesDocument $document): RedirectResponse
    {
        $this->authorize('writeOff', $document);
        $data = $request->validate(['amount' => ['required', new MoneyAmount], 'reason' => ['required', 'string', 'max:255']]);

        try {
            $result = app(WriteOffs::class)->request($document, Money::parse($data['amount']), $data['reason'], $request->user());
        } catch (SalesViolation $e) {
            abort_if($e->reason === 'missing_capability', 403);

            return back()->withInput()->withErrors(['sales' => $e->getMessage()]);
        }

        return back()->with('status', $result['applied'] ? 'Written off and posted to bad debts.' : 'Sent for approval. Nothing is posted until it is approved.');
    }

    public function print(SalesDocument $document, DocumentRenderer $renderer): View
    {
        $this->authorize('view', $document);

        return view('sales.print.page', $renderer->data($document) + ['back' => route('sales.documents.show', $document)]);
    }

    public function pdf(SalesDocument $document, DocumentRenderer $renderer): Response
    {
        $this->authorize('view', $document);

        return response($renderer->pdf($document), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$renderer->filename($document).'"', 'Cache-Control' => 'private, no-store']);
    }

    // ---- internals ----------------------------------------------------------------------------

    private function editor(SalesDocument $document, $lines, $profile): View
    {
        $context = $this->ledger();
        $on = CarbonImmutable::today();

        return view('sales.documents.editor', [
            'document' => $document, 'lines' => $lines, 'profile' => $profile,
            'customers' => $this->customers(), 'currencies' => Currency::query()->where('is_active', true)->where('minor_units', 2)->orderBy('code')->pluck('code'),
            'taxCodes' => $profile->vat_registered ? app(TaxRuleBook::class)->taxCodesOn($profile->tax_jurisdiction ?: 'ZA', $on, 'sales') : collect(),
            'incomeAccounts' => GlAccount::query()->where('class', 'income')->where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
            'baseCurrency' => LedgerSetting::query()->first()?->base_currency ?? 'ZAR', 'token' => (string) Str::uuid(),
        ]);
    }
}
