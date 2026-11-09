<?php

namespace App\Http\Controllers\Sales;

use App\Models\Account;
use App\Models\Sales\SalesApplication;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRefund;
use App\Models\Shared\Payment;
use App\Rules\MoneyAmount;
use App\Services\Sales\ApplicationEngine;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Money received from customers: record it, apply it to invoices, give credit back, take a payment back. */
class PaymentController extends SalesController
{
    public function index(Request $request): View
    {
        $this->allow('view-finance');
        $filters = $request->validate(['party' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['recorded', 'reversed'])]]);

        $payments = Payment::query()->whereNotNull('party_id')->with('party', 'cashAccount')
            ->when($filters['party'] ?? null, fn ($q, $id) => $q->where('party_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('paid_on')->orderByDesc('id')->paginate(25)->withQueryString();

        $unapplied = app(ReceivablesLedger::class)->unappliedCredits($this->ledger())->where('kind', 'payment')->keyBy('id');

        return view('sales.payments.index', ['payments' => $payments, 'unapplied' => $unapplied, 'customers' => $this->customers(), 'filters' => $filters + ['party' => null, 'status' => null]]);
    }

    public function create(Request $request): View
    {
        $this->allow('record-payment');
        $partyId = $request->integer('party') ?: null;
        $documentId = $request->integer('document') ?: null;
        $document = $documentId ? SalesDocument::query()->receivable()->findOrFail($documentId) : null;
        $partyId ??= $document?->party_id;

        $engine = app(ApplicationEngine::class);
        $open = $partyId
            ? SalesDocument::query()->open()->where('party_id', $partyId)->orderBy('due_date')->orderBy('id')->get()->map(fn ($d) => ['document' => $d, 'balance' => $engine->openBalance($d)])->filter(fn ($r) => $r['balance'] > 0)->values()
            : collect();

        return view('sales.payments.create', [
            'customers' => $this->customers(), 'accounts' => Account::query()->whereIn('type', ['checking', 'savings', 'cash'])->orderBy('name')->get(),
            'partyId' => $partyId, 'open' => $open, 'document' => $document, 'key' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow('record-payment');
        $data = $request->validate([
            'party_id' => ['required', 'integer'], 'cash_account_id' => ['required', 'integer'], 'amount' => ['required', new MoneyAmount], 'paid_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['nullable', Rule::in(Payment::METHODS)], 'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:80'], 'fx_rate' => ['nullable', 'string', 'max:20'], 'auto_allocate' => ['nullable', 'boolean'],
            'allocations' => ['nullable', 'array', 'max:200'], 'allocations.*' => ['nullable', new MoneyAmount(allowZero: true)],
        ]);

        $allocations = [];
        foreach ($data['allocations'] ?? [] as $documentId => $amount) {
            if ($amount !== null && $amount !== '' && Money::parse((string) $amount) > 0) {
                $allocations[] = ['document_id' => (int) $documentId, 'amount_cents' => Money::parse((string) $amount)];
            }
        }

        try {
            $out = app(ReceiptService::class)->receive($this->ledger(), $request->user(), [
                'party_id' => (int) $data['party_id'], 'cash_account_id' => (int) $data['cash_account_id'], 'amount_cents' => Money::parse($data['amount']), 'paid_on' => $data['paid_on'],
                'method' => $data['method'] ?? 'eft', 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'idempotency_key' => $data['idempotency_key'],
                'fx_rate' => $data['fx_rate'] ?? null, 'allocations' => $allocations, 'auto_allocate' => (bool) ($data['auto_allocate'] ?? false) && $allocations === [],
            ]);
        } catch (SalesViolation $e) {
            return back()->withInput()->withErrors(['sales' => $e->getMessage()]);
        }

        $note = $out['replayed'] ? 'That payment was already recorded; nothing was recorded twice.' : 'Recorded '.Money::format((int) $out['payment']->amount_cents, $out['payment']->currency).'.';
        if ($out['unapplied_cents'] > 0) {
            $note .= ' '.Money::format($out['unapplied_cents'], $out['payment']->currency).' is not applied to any invoice yet and stays as customer credit.';
        }

        return redirect()->route('sales.payments.show', $out['payment'])->with('status', $note);
    }

    public function show(Payment $payment): View
    {
        $this->allow('view-finance');
        abort_unless($payment->party_id !== null, 404);
        $payment->load('party', 'cashAccount');
        $engine = app(ApplicationEngine::class);
        $apps = SalesApplication::query()->where('payment_id', $payment->id)->with('target')->orderBy('id')->get();
        $refund = $payment->direction === 'out' ? SalesRefund::query()->where('payment_id', $payment->id)->first() : null;
        $pool = $payment->direction === 'in' ? $engine->paymentPool($payment) : null;
        $open = $pool && $pool['foreign'] > 0
            ? SalesDocument::query()->open()->where('party_id', $payment->party_id)->where('currency', $payment->currency)->orderBy('due_date')->get()->map(fn ($d) => ['document' => $d, 'balance' => $engine->openBalance($d)])->filter(fn ($r) => $r['balance'] > 0)->values()
            : collect();

        return view('sales.payments.show', ['payment' => $payment, 'applications' => $apps, 'pool' => $pool, 'open' => $open, 'refund' => $refund, 'accounts' => Account::query()->where('currency', $payment->currency)->orderBy('name')->get(), 'key' => (string) Str::uuid()]);
    }

    public function allocate(Request $request, Payment $payment): RedirectResponse
    {
        $this->allow('record-payment');
        $data = $request->validate(['document_id' => ['required', 'integer'], 'amount' => ['required', new MoneyAmount]]);
        $document = SalesDocument::query()->receivable()->findOrFail($data['document_id']);

        return $this->attempt(fn () => app(ReceiptService::class)->allocate($this->ledger(), $request->user(), $payment, $document, Money::parse($data['amount'])), 'Applied to '.$document->number.'.');
    }

    public function release(Request $request, SalesApplication $application): RedirectResponse
    {
        $this->allow('record-payment');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => app(ApplicationEngine::class)->release($application, $data['reason'], $request->user()), 'Taken back. The invoice owes that amount again.');
    }

    public function reverse(Request $request, Payment $payment): RedirectResponse
    {
        $this->allow('record-payment');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => app(ReceiptService::class)->reverse($this->ledger(), $request->user(), $payment, $data['reason']), 'Reversed. The posting was reversed, the cash row removed and the invoices owe again.');
    }

    /** Give money back out of a customer credit (an unapplied payment or credit note). Needs the right to approve payments. */
    public function refund(Request $request): RedirectResponse
    {
        $this->allow('approve-payment');
        $data = $request->validate([
            'source_payment_id' => ['nullable', 'integer'], 'source_document_id' => ['nullable', 'integer'], 'amount' => ['required', new MoneyAmount], 'cash_account_id' => ['required', 'integer'],
            'paid_on' => ['required', 'date_format:Y-m-d'], 'reference' => ['nullable', 'string', 'max:100'], 'reason' => ['required', 'string', 'max:255'], 'idempotency_key' => ['required', 'string', 'max:80'],
            'fx_rate' => ['nullable', 'string', 'max:20'],
        ]);
        abort_unless(filled($data['source_payment_id'] ?? null) xor filled($data['source_document_id'] ?? null), 422);

        return $this->attempt(fn () => app(ReceiptService::class)->refund($this->ledger(), $request->user(), [
            'source_payment_id' => $data['source_payment_id'] ?? null, 'source_document_id' => $data['source_document_id'] ?? null, 'amount_cents' => Money::parse($data['amount']),
            'cash_account_id' => (int) $data['cash_account_id'], 'paid_on' => $data['paid_on'], 'reference' => $data['reference'] ?? null, 'reason' => $data['reason'],
            'idempotency_key' => $data['idempotency_key'], 'fx_rate' => $data['fx_rate'] ?? null,
        ]), 'Refund recorded.');
    }
}
