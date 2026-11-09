<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Capability;
use App\Models\Account;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Services\Purchases\BillStatus;
use App\Services\Purchases\PaymentRunService;
use App\Services\Purchases\PurchaseLedger;
use App\Services\Purchases\PurchasesException;
use App\Services\Purchases\Settlement;
use App\Services\Purchases\SupplierPayments;
use App\Services\Shared\FxMath;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Paying suppliers: one payment settles any number of the supplier's bills and claims, with part payments, early-payment discounts and
 * money on account. The server decides every amount that matters; sending the form twice pays once.
 */
class SupplierPaymentController extends PurchasesController
{
    public function __construct(private readonly SupplierPayments $payments, private readonly BillStatus $status) {}

    public function index(): View
    {
        $this->authorize('viewAny', Payment::class);

        $payments = Payment::query()->with('party')->where('direction', 'out')->orderByDesc('paid_on')->orderByDesc('id')->paginate(25);

        return view('purchases.payments.index', ['payments' => $payments, 'canPay' => $this->gate(Capability::RecordPayment)]);
    }

    public function create(Request $request): View
    {
        $this->requireCapability(Capability::RecordPayment);

        $party = $request->integer('party_id') ? Party::query()->suppliers()->find($request->integer('party_id')) : null;
        $accounts = Account::query()->whereIn('type', ['checking', 'savings', 'cash', 'credit'])->orderBy('name')->get();
        $open = collect();

        if ($party !== null) {
            $paidOn = CarbonImmutable::parse($request->query('paid_on', now()->toDateString()));
            $open = Bill::query()->where('party_id', $party->id)->where('kind', 'bill')->whereIn('status', ['open', 'partially_paid'])->orderBy('due_date')->get()
                ->map(fn (Bill $b) => $this->row('bill', $b, $paidOn))
                ->merge(ExpenseClaim::query()->where('party_id', $party->id)->whereIn('status', ['open', 'partially_paid'])->orderBy('claim_date')->get()->map(fn (ExpenseClaim $c) => $this->row('expense_claim', $c, $paidOn)))
                ->filter(fn ($r) => $r['open'] > 0)->values();
        }

        return view('purchases.payments.create', [
            'suppliers' => $this->suppliers(), 'party' => $party, 'accounts' => $accounts, 'open' => $open, 'token' => Str::uuid()->toString(),
            'baseCurrency' => app(PurchaseLedger::class)->baseCurrencyOrDefault($this->ledger()), 'preselect' => $request->query('bill'),
        ]);
    }

    public function store(Request $request, PaymentRunService $runs): RedirectResponse
    {
        $this->requireCapability(Capability::RecordPayment);

        $v = $request->validate([
            'party_id' => ['required', 'integer'], 'cash_account_id' => ['required', 'integer'], 'paid_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['nullable', Rule::in(Payment::METHODS)], 'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:2000'],
            'fx_rate' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,8})?$/'], 'idempotency_key' => ['required', 'string', 'max:80'],
            'pay' => ['nullable', 'array'], 'pay.*' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'], 'discount' => ['nullable', 'array'], 'discount.*' => ['nullable', 'boolean'],
            'on_account' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ]);

        $allocations = [];
        foreach ((array) ($v['pay'] ?? []) as $key => $amount) {
            [$type, $id] = array_pad(explode('-', (string) $key, 2), 2, null);
            $take = ! empty($v['discount'][$key]);
            if (! in_array($type, ['bill', 'expense_claim'], true) || ! ctype_digit((string) $id) || (trim((string) $amount) === '' && ! $take)) {
                continue;
            }
            $allocations[] = ['type' => $type, 'id' => (int) $id, 'take_discount' => $take] + (trim((string) $amount) !== '' ? ['amount_cents' => Money::parse($amount)] : []);
        }

        $data = [
            'party_id' => (int) $v['party_id'], 'cash_account_id' => (int) $v['cash_account_id'], 'paid_on' => $v['paid_on'], 'method' => $v['method'] ?? 'eft',
            'reference' => $v['reference'] ?? null, 'notes' => $v['notes'] ?? null, 'idempotency_key' => $v['idempotency_key'], 'allocations' => $allocations,
        ];
        if (! empty($v['fx_rate'])) {
            try {
                $data['fx_rate_e8'] = FxMath::parseRate($v['fx_rate']);
            } catch (InvalidArgumentException) {
            }
        }
        if (! empty($v['on_account']) && (float) $v['on_account'] > 0) {
            $data['amount_cents'] = array_sum(array_map(fn ($a) => $a['amount_cents'] ?? 0, $allocations)) + Money::parse($v['on_account']);
        }

        if ($allocations === [] && empty($v['on_account'])) {
            return back()->withInput()->withErrors(['purchases' => 'Choose what you are paying: enter an amount against at least one bill, or an amount to pay on account.']);
        }

        try {
            $payment = $this->payments->pay($this->ledger(), $this->user($request), $data);
        } catch (PurchasesException $e) {
            if ($e->reason === 'approval_required' && $allocations !== [] && collect($allocations)->every(fn ($a) => isset($a['amount_cents']))) {
                return $this->prepareRun($request, $runs, $data, $allocations);
            }

            return back()->withInput()->withErrors(['purchases' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->attempt(fn () => throw $e);
        }

        return redirect()->route('purchases.payments.show', $payment)->with('status', 'Payment recorded and posted to the books.');
    }

    public function show(Payment $payment, Settlement $settlement): View
    {
        $this->outOnly($payment);
        $this->authorize('view', $payment);

        $payment->load('party', 'cashAccount');
        $allocations = $payment->allocations()->with('allocatable')->get();

        return view('purchases.payments.show', [
            'payment' => $payment, 'allocations' => $allocations, 'unapplied' => $settlement->unappliedOf($payment), 'canReverse' => $this->gate(Capability::RecordPayment) && ! $payment->isReversed(),
            'applicable' => $payment->party_id && ! $payment->isReversed() ? Bill::query()->where('party_id', $payment->party_id)->where('kind', 'bill')->whereIn('status', ['open', 'partially_paid'])->where('currency', $payment->currency)->get() : collect(),
        ]);
    }

    public function reverse(Request $request, Payment $payment): RedirectResponse
    {
        $this->outOnly($payment);
        $this->requireCapability(Capability::RecordPayment);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        return $this->attempt(function () use ($request, $payment, $reason) {
            $this->payments->reverse($this->user($request), $payment, $reason);

            return redirect()->route('purchases.payments.show', $payment)->with('status', 'Payment reversed. The bills it paid are open again.');
        });
    }

    /** Apply money paid on account to a bill later (the ledger's own currency). */
    public function apply(Request $request, Payment $payment): RedirectResponse
    {
        $this->outOnly($payment);
        $this->requireCapability(Capability::RecordPayment);
        $v = $request->validate(['bill_id' => ['required', 'integer'], 'amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/']]);
        $bill = Bill::query()->where('kind', 'bill')->findOrFail($v['bill_id']);

        return $this->attempt(function () use ($request, $payment, $bill, $v) {
            $this->payments->applyOnAccount($this->user($request), $payment, $bill, Money::parse($v['amount']));

            return redirect()->route('purchases.payments.show', $payment)->with('status', 'Applied to '.$bill->displayNumber().'.');
        });
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function outOnly(Payment $payment): void
    {
        abort_unless($payment->direction === 'out', 404);
    }

    /** @return array<string, mixed> */
    private function row(string $type, Bill|ExpenseClaim $doc, CarbonImmutable $paidOn): array
    {
        $open = $this->status->openCents($doc);
        $discount = null;

        if ($doc instanceof Bill && $doc->early_payment_discount_bp && $doc->early_payment_days) {
            $until = CarbonImmutable::parse($doc->bill_date->toDateString())->addDays($doc->early_payment_days);
            $taken = Bill::query()->where('credit_for_bill_id', $doc->id)->where('credit_reason', 'Early-payment discount')->where('status', '!=', 'void')->exists();
            if (! $taken && $paidOn->lessThanOrEqualTo($until)) {
                $discount = ['bp' => $doc->early_payment_discount_bp, 'until' => $until->toDateString(), 'approx_cents' => Money::mulDivRound($doc->total_cents, $doc->early_payment_discount_bp, 10_000)];
            }
        }

        return ['key' => $type.'-'.$doc->id, 'type' => $type, 'doc' => $doc, 'number' => $doc->displayNumber(), 'due' => $doc instanceof Bill ? $doc->due_date?->toDateString() : $doc->claim_date->toDateString(), 'currency' => $doc->currency, 'open' => $open, 'discount' => $discount];
    }

    /** The payment is big enough to need approval: make it a one-supplier payment run and send that for approval. */
    private function prepareRun(Request $request, PaymentRunService $runs, array $data, array $allocations): RedirectResponse
    {
        try {
            $run = $runs->create($this->ledger(), $this->user($request), [
                'name' => 'Payment to '.Party::query()->find($data['party_id'])?->name, 'due_by' => CarbonImmutable::parse($data['paid_on'])->addYears(10)->toDateString(), 'paid_on' => $data['paid_on'],
                'cash_account_id' => $data['cash_account_id'], 'idempotency_key' => 'adhoc-'.$data['idempotency_key'], 'selection' => $allocations,
            ]);
            $runs->submit($this->user($request), $run);
        } catch (PurchasesException $e) {
            return back()->withInput()->withErrors(['purchases' => 'This payment needs approval, and it could not be prepared as a payment run: '.$e->getMessage()]);
        }

        return redirect()->route('purchases.runs.show', $run)->with('status', 'A payment this size needs approval, so it was prepared as a payment run and sent for approval.');
    }
}
