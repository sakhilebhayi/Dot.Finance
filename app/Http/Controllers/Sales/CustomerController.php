<?php

namespace App\Http\Controllers\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesReminder;
use App\Models\Sales\SalesShareLink;
use App\Models\Sales\SalesTransactionLink;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Transaction;
use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Sales\Reminders;
use App\Services\Sales\ShareLinks;
use App\Services\Sales\Statements;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The customer list with what each owes, the customer 360 page, statements, and the customer's reminder preference. */
class CustomerController extends SalesController
{
    public function index(Request $request, ReceivablesLedger $ledger): View
    {
        $this->allow('view-finance');
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'owing' => ['nullable', 'boolean']]);
        $context = $this->ledger();

        $parties = Party::query()->customers()->active()
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function ($q) use ($filters) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';
                $q->where(fn ($w) => $w->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '\\'", [$like])->orWhereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '\\'", [$like]));
            })->orderBy('name')->get();

        $ageing = collect($ledger->ageing($context)['rows'])->keyBy(fn ($r) => $r['party']?->id);
        $rows = $parties->map(fn (Party $p) => ['party' => $p, 'age' => $ageing->get($p->id)])
            ->when(! empty($filters['owing']), fn ($c) => $c->filter(fn ($r) => $r['age'] && $r['age']['total'] !== 0))->values();

        return view('sales.customers.index', ['rows' => $rows, 'filters' => $filters + ['q' => null, 'owing' => null], 'totals' => $ledger->ageing($context)['totals'], 'canCreate' => request()->user()->can('create', Party::class)]);
    }

    public function show(Party $party, ReceivablesLedger $ledger, Reminders $reminders): View
    {
        $this->allow('view-finance');
        $context = $this->ledger();
        abort_unless($party->isCustomer(), 404);

        $docs = SalesDocument::query()->where('party_id', $party->id)->whereNotNull('sealed_at')->orderByDesc('issue_date')->orderByDesc('id')->limit(30)->get();
        $drafts = SalesDocument::query()->where('party_id', $party->id)->whereIn('status', ['draft', 'awaiting_approval'])->orderByDesc('id')->get();

        return view('sales.customers.show', [
            'party' => $party, 'docs' => $docs, 'drafts' => $drafts, 'balances' => $ledger->balances($context, $docs),
            'open' => $ledger->openItems($context, $party->id), 'credits' => $ledger->unappliedCredits($context, $party->id), 'age' => $ledger->ageing($context, 'due', null, $party->id)['totals'],
            'payments' => Payment::query()->where('party_id', $party->id)->where('direction', 'in')->orderByDesc('paid_on')->orderByDesc('id')->limit(10)->get(),
            'balanceBase' => $ledger->customerBalanceBase($context, $party->id), 'optedOut' => $reminders->optedOut($party),
            'reminderLog' => SalesReminder::query()->where('party_id', $party->id)->orderByDesc('id')->limit(8)->get(),
            'links' => SalesTransactionLink::query()->where('party_id', $party->id)->with('transaction', 'document')->orderByDesc('id')->limit(10)->get(),
            'linkable' => Transaction::query()->where('type', 'income')->whereNull('settlement_ref')->whereNotIn('id', SalesTransactionLink::query()->select('transaction_id'))->orderByDesc('date')->limit(30)->get(),
            'sinceYear' => SalesDocument::query()->where('party_id', $party->id)->where('kind', 'tax_invoice')->whereNotNull('sealed_at')->where('status', '<>', 'void')->where('issue_date', '>=', now()->subYear()->toDateString())->sum('base_total_cents'),
        ]);
    }

    public function reminders(Request $request, Party $party, Reminders $reminders): RedirectResponse
    {
        $this->allow('issue-invoice');
        $data = $request->validate(['opt_out' => ['required', 'boolean']]);
        $reminders->setOptOut($party, (bool) $data['opt_out']);

        return back()->with('status', $data['opt_out'] ? $party->name.' will not be sent payment reminders.' : $party->name.' will be sent payment reminders again.');
    }

    public function linkTransaction(Request $request, Party $party): RedirectResponse
    {
        $this->allow('record-entries');
        $data = $request->validate(['transaction_id' => ['required', 'integer'], 'document_id' => ['nullable', 'integer']]);
        $transaction = Transaction::query()->where('type', 'income')->whereNull('settlement_ref')->findOrFail($data['transaction_id']);
        $document = ! empty($data['document_id']) ? SalesDocument::query()->where('party_id', $party->id)->findOrFail($data['document_id']) : null;

        SalesTransactionLink::query()->firstOrCreate(['transaction_id' => $transaction->id], $this->ledger()->ownerAttributes() + ['party_id' => $party->id, 'document_id' => $document?->id, 'created_by' => $request->user()->id]);

        return back()->with('status', 'Linked. The transaction posts as it always did; this only ties it to '.$party->name.($document ? ' and '.$document->number : '').'.');
    }

    // ---- statements ---------------------------------------------------------------------------

    public function statement(Request $request, Party $party, Statements $statements): View
    {
        $this->allow('view-finance');
        abort_unless($party->isCustomer(), 404);
        [$from, $to] = $this->period($request);

        return view('sales.customers.statement', [
            'party' => $party, 'statement' => $statements->build($this->ledger(), $party, $from, $to), 'from' => $from, 'to' => $to,
            'links' => SalesShareLink::query()->where('party_id', $party->id)->where('kind', 'statement')->orderByDesc('id')->limit(5)->get(), 'newLink' => session('new_link'),
        ]);
    }

    public function statementCsv(Request $request, Party $party, Statements $statements): Response
    {
        $this->allow('view-finance');
        [$from, $to] = $this->period($request);
        $rows = $statements->csv($statements->build($this->ledger(), $party, $from, $to));
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="statement-'.Str::slug($party->name).'-'.$to->toDateString().'.csv"']);
    }

    public function statementPdf(Request $request, Party $party, Statements $statements, DocumentRenderer $renderer): Response
    {
        $this->allow('view-finance');
        [$from, $to] = $this->period($request);

        return response($renderer->statementPdf($statements->build($this->ledger(), $party, $from, $to)), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="statement-'.Str::slug($party->name).'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function statementShare(Request $request, Party $party, ShareLinks $links): RedirectResponse
    {
        $this->allow('issue-invoice');
        [$from, $to] = $this->period($request);
        $made = $links->forStatement($this->ledger(), $party, $request->user(), $from->toDateString(), $to->toDateString());

        return back()->with('status', 'Link made. Copy it now: it is shown once.')->with('new_link', $made['url']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::today();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subMonths(3)->startOfMonth();

        if ($from->gt($to)) {
            throw ValidationException::withMessages(['from' => 'The start of the period cannot be after its end.']);
        }

        return [$from, $to];
    }
}
