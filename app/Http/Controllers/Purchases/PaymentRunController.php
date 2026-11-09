<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Capability;
use App\Models\Account;
use App\Models\Purchases\PaymentRun;
use App\Models\Purchases\PaymentRunItem;
use App\Services\Controls\ApprovalService;
use App\Services\Purchases\PaymentRunService;
use App\Services\Purchases\PurchaseLedger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Payment runs: choose the bills due by a date, pay them in one idempotent batch, export a generic CSV. */
class PaymentRunController extends PurchasesController
{
    public function __construct(private readonly PaymentRunService $runs) {}

    public function index(): View
    {
        $this->authorize('viewAny', PaymentRun::class);

        return view('purchases.runs.index', ['runs' => PaymentRun::query()->orderByDesc('id')->paginate(20), 'canRun' => $this->gate(Capability::RecordPayment)]);
    }

    public function create(Request $request): View
    {
        $this->requireCapability(Capability::RecordPayment);

        $base = app(PurchaseLedger::class)->baseCurrencyOrDefault($this->ledger());
        $accounts = Account::query()->where('currency', $base)->whereIn('type', ['checking', 'savings', 'cash'])->orderBy('name')->get();
        $dueBy = CarbonImmutable::parse($request->validate(['due_by' => ['nullable', 'date_format:Y-m-d']])['due_by'] ?? now()->addDays(7)->toDateString());

        return view('purchases.runs.create', [
            'accounts' => $accounts, 'dueBy' => $dueBy, 'groups' => $this->runs->candidates($this->ledger(), $dueBy, $base), 'token' => Str::uuid()->toString(), 'baseCurrency' => $base,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireCapability(Capability::RecordPayment);

        $v = $request->validate([
            'name' => ['nullable', 'string', 'max:120'], 'due_by' => ['required', 'date_format:Y-m-d'], 'paid_on' => ['required', 'date_format:Y-m-d'], 'cash_account_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:80'], 'pick' => ['required', 'array', 'min:1'], 'pick.*' => ['nullable', 'regex:/^(bill|expense_claim)-\d+$/'],
        ], ['pick.required' => 'Choose at least one bill to pay.']);

        $selection = [];
        foreach ((array) $v['pick'] as $value) {
            if ($value === null) {
                continue;
            }
            [$type, $id] = explode('-', $value, 2);
            $selection[] = ['type' => $type, 'id' => (int) $id];
        }

        return $this->attempt(function () use ($request, $v, $selection) {
            $run = $this->runs->create($this->ledger(), $this->user($request), $v + ['selection' => $selection]);

            return redirect()->route('purchases.runs.show', $run)->with('status', 'The run is ready. Check it, then send it for approval or pay it.');
        });
    }

    public function show(PaymentRun $run): View
    {
        $this->authorize('view', $run);

        $items = PaymentRunItem::query()->with('party')->where('payment_run_id', $run->id)->get()->groupBy('party_id');
        $approval = $run->status === 'draft' ? null : app(ApprovalService::class)->latestFor($run);

        return view('purchases.runs.show', [
            'run' => $run->load('cashAccount'), 'items' => $items, 'approval' => $approval, 'canRun' => $this->gate(Capability::RecordPayment),
            'needsApproval' => ApprovalService::requiresApproval('payment', $run->total_cents, $run->currency, $this->ledger()),
        ]);
    }

    public function submit(Request $request, PaymentRun $run): RedirectResponse
    {
        $this->requireCapability(Capability::RecordPayment);
        $this->authorize('view', $run);

        return $this->attempt(function () use ($request, $run) {
            $done = $this->runs->submit($this->user($request), $run);

            return redirect()->route('purchases.runs.show', $done)->with('status', $done->status === 'awaiting_approval' ? 'Sent for approval.' : 'No approval is needed for this amount. You can pay it now.');
        });
    }

    public function execute(Request $request, PaymentRun $run): RedirectResponse
    {
        $this->requireCapability(Capability::RecordPayment);
        $this->authorize('view', $run);

        return $this->attempt(function () use ($request, $run) {
            $done = $this->runs->execute($this->user($request), $run);

            return redirect()->route('purchases.runs.show', $done)->with('status', 'Paid. '.$done->supplier_count.' supplier payment'.($done->supplier_count === 1 ? '' : 's').' recorded and posted to the books.');
        });
    }

    public function cancel(Request $request, PaymentRun $run): RedirectResponse
    {
        $this->requireCapability(Capability::RecordPayment);
        $this->authorize('view', $run);

        return $this->attempt(function () use ($request, $run) {
            $this->runs->cancel($this->user($request), $run);

            return redirect()->route('purchases.runs.show', $run)->with('status', 'The run was cancelled. Nothing was paid.');
        });
    }

    public function csv(Request $request, PaymentRun $run): StreamedResponse|RedirectResponse
    {
        $this->requireCapability(Capability::RecordPayment);
        $this->authorize('view', $run);
        abort_unless(in_array($run->status, ['approved', 'draft', 'executed'], true), 409);

        $file = $this->runs->csv($this->user($request), $run);
        if ($file['rows'] === 0) {
            return back()->withErrors(['purchases' => 'Nothing to export: no supplier in this run has approved bank details. '.implode('; ', $file['skipped'])]);
        }

        return Response::streamDownload(function () use ($file) {
            echo $file['csv'];
        }, 'payment-run-'.$run->id.'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
