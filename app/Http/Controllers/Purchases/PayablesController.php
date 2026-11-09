<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Capability;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Purchases\PurchaseSetting;
use App\Rules\MoneyAmount;
use App\Services\Purchases\ApLedger;
use App\Services\Purchases\ExpenseTrends;
use App\Services\Purchases\MissingDocuments;
use App\Services\Purchases\PurchaseLedger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The Purchases overview, the supplier ledger with ageing and its reconcile check, and the settings. */
class PayablesController extends PurchasesController
{
    public function overview(ApLedger $ap, MissingDocuments $missing, ExpenseTrends $trends, PurchaseLedger $ledger): View
    {
        Gate::authorize(Capability::ViewFinance->value);
        $context = $this->ledger();
        $today = CarbonImmutable::today();

        $dueSoon = Bill::query()->with('party')->where('kind', 'bill')->whereIn('status', ['open', 'partially_paid'])->whereBetween('due_date', [$today->toDateString(), $today->addDays(14)->toDateString()])->orderBy('due_date')->limit(8)->get();
        $overdue = Bill::query()->with('party')->where('kind', 'bill')->whereIn('status', ['open', 'partially_paid'])->where('due_date', '<', $today->toDateString())->orderBy('due_date')->limit(8)->get();

        return view('purchases.overview', [
            'on' => $ledger->accountingEnabled($context), 'drafts' => Bill::query()->where('status', 'draft')->count(), 'awaiting' => Bill::query()->where('status', 'awaiting_approval')->count() + ExpenseClaim::query()->where('status', 'awaiting_approval')->count(),
            'unposted' => Bill::query()->where('status', 'approved')->count() + ExpenseClaim::query()->where('status', 'approved')->count(), 'dueSoon' => $dueSoon, 'overdue' => $overdue,
            'ageing' => $ap->ageing($context), 'reconcile' => $ap->reconcile($context), 'missing' => $missing->forLedger($context)->take(6), 'missingCount' => $missing->countForLedger($context),
            'movers' => $ledger->accountingEnabled($context) ? $trends->movers($context, 5) : collect(), 'base' => $ledger->baseCurrencyOrDefault($context),
            'canBill' => $this->gate(Capability::EnterBill), 'canPay' => $this->gate(Capability::RecordPayment), 'settings' => PurchaseSetting::forLedger($context),
        ]);
    }

    public function ap(Request $request, ApLedger $ap): View|StreamedResponse
    {
        Gate::authorize(Capability::ViewFinance->value);

        $v = $request->validate(['as_at' => ['nullable', 'date_format:Y-m-d'], 'format' => ['nullable', 'in:csv'], 'party_id' => ['nullable', 'integer']]);
        $asAt = CarbonImmutable::parse($v['as_at'] ?? now()->toDateString());
        $context = $this->ledger();

        $items = $ap->openItems($context, $asAt, isset($v['party_id']) ? (int) $v['party_id'] : null);

        if (($v['format'] ?? null) === 'csv') {
            return Response::streamDownload(function () use ($items, $asAt, $ap, $context) {
                $out = fopen('php://output', 'w');
                $rec = $ap->reconcile($context);
                fputcsv($out, ['Accounts payable, open items', 'Ages measured at '.$asAt->toDateString(), 'Base currency amounts']);
                fputcsv($out, ['Supplier', 'Type', 'Document', 'Date', 'Due', 'Currency', 'Open (document currency)', 'Open (base currency)', 'Days overdue', 'Age bucket']);
                foreach ($items as $i) {
                    fputcsv($out, array_map(fn ($c) => $this->csvSafe($c), [$i['party'], $i['type'], $i['number'], $i['date'], $i['due_date'], $i['currency'], Money::toDecimalString($i['open']), Money::toDecimalString($i['open_base']), $i['days_overdue'], ApLedger::BUCKET_LABELS[$i['bucket']]]));
                }
                fputcsv($out, ['Total', '', '', '', '', '', '', Money::toDecimalString($items->sum('open_base'))]);
                fputcsv($out, ['The books (accounts payable)', '', '', '', '', '', '', Money::toDecimalString($rec['books_cents']), $rec['ok'] ? 'Agrees' : 'DIFFERENCE '.Money::toDecimalString($rec['difference_cents'])]);
                fclose($out);
            }, 'payables-'.$asAt->toDateString().'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
        }

        return view('purchases.ap', ['items' => $items, 'ageing' => $ap->ageing($context, $asAt), 'reconcile' => $ap->reconcile($context), 'asAt' => $asAt, 'base' => app(PurchaseLedger::class)->baseCurrencyOrDefault($context), 'partyId' => $v['party_id'] ?? null]);
    }

    public function editSettings(): View
    {
        Gate::authorize(Capability::ViewFinance->value);

        return view('purchases.settings', ['settings' => PurchaseSetting::forLedger($this->ledger()), 'canManage' => $this->gate(Capability::ManageTax), 'default' => PurchaseSetting::forLedger($this->ledger())->missingDocumentThresholdCents()]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->requireCapability(Capability::ManageTax);

        $v = $request->validate([
            'vat_registered' => ['required', 'in:yes,no,unknown'],
            'missing_document_threshold' => ['nullable', new MoneyAmount(allowZero: true)],
        ]);

        $settings = PurchaseSetting::forLedger($this->ledger());
        $registered = match ($v['vat_registered']) {
            'yes' => true, 'no' => false, default => null,
        };

        $settings->fill($this->ledger()->ownerAttributes() + [
            'vat_registered' => $registered,
            'vat_registered_set_by' => $registered !== $settings->vat_registered || $settings->vat_registered_set_by === null ? $request->user()->id : $settings->vat_registered_set_by,
            'vat_registered_set_at' => $registered !== $settings->vat_registered || $settings->vat_registered_set_at === null ? now() : $settings->vat_registered_set_at,
            'missing_document_threshold_cents' => isset($v['missing_document_threshold']) && $v['missing_document_threshold'] !== '' ? Money::parse($v['missing_document_threshold']) : null,
        ])->save();

        return redirect()->route('purchases.settings.edit')->with('status', 'Saved.');
    }

    private function csvSafe(mixed $cell): string
    {
        $cell = (string) $cell;

        return $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$cell : $cell;
    }
}
