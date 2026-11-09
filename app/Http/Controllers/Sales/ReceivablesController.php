<?php

namespace App\Http\Controllers\Sales;

use App\Services\Sales\ReceivablesLedger;
use App\Support\FinanceAudit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** What customers owe: ageing, customer credit, and the proof that it equals the receivables account of the books. */
class ReceivablesController extends SalesController
{
    public function index(Request $request, ReceivablesLedger $ledger): View
    {
        $this->allow('view-finance');
        $filters = $request->validate(['basis' => ['nullable', Rule::in(['due', 'invoice'])], 'as_of' => ['nullable', 'date_format:Y-m-d']]);
        $basis = $filters['basis'] ?? 'due';
        $asOf = isset($filters['as_of']) ? CarbonImmutable::parse($filters['as_of']) : CarbonImmutable::today();
        $context = $this->ledger();

        return view('sales.receivables.index', [
            'ageing' => $ledger->ageing($context, $basis, $asOf), 'reconcile' => $ledger->reconcile($context), 'credits' => $ledger->unappliedCredits($context),
            'items' => $ledger->openItems($context, null, 15), 'itemCount' => $ledger->openCount($context), 'basis' => $basis, 'asOf' => $asOf, 'canExport' => Gate::allows('export-reports'),
        ]);
    }

    public function csv(Request $request, ReceivablesLedger $ledger): Response
    {
        $this->allow('export-reports');
        $basis = $request->query('basis') === 'invoice' ? 'invoice' : 'due';
        $age = $ledger->ageing($this->ledger(), $basis);
        FinanceAudit::exported($this->ledger()->ownerAttributes(), 'Receivables ageing (CSV)', ['report' => 'receivables', 'format' => 'csv', 'as_of' => $age['as_of'], 'basis' => $basis]);
        $safe = fn (string $v) => $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
        $money = fn (int $c) => Money::toDecimalString($c);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Receivables ageing', 'as at '.$age['as_of'], 'basis: days past '.($basis === 'invoice' ? 'invoice date' : 'due date'), 'currency: base currency of the ledger']);
        fputcsv($out, array_merge(['Customer'], array_values(ReceivablesLedger::BUCKETS), ['On account', 'Total']));
        foreach ($age['rows'] as $row) {
            fputcsv($out, array_merge([$safe((string) $row['party']?->name)], array_map(fn ($k) => $money($row[$k]), array_keys(ReceivablesLedger::BUCKETS)), [$money($row['on_account']), $money($row['total'])]));
        }
        fputcsv($out, array_merge(['Total'], array_map(fn ($k) => $money($age['totals'][$k]), array_keys(ReceivablesLedger::BUCKETS)), [$money($age['totals']['on_account']), $money($age['totals']['total'])]));
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="receivables-ageing-'.$age['as_of'].'.csv"']);
    }
}
