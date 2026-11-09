<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Capability;
use App\Models\Purchases\Bill;
use App\Models\Purchases\SupplierBankDetail;
use App\Models\Purchases\SupplierProfile;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Services\Purchases\ApLedger;
use App\Services\Purchases\SupplierBankDetails;
use App\Services\Purchases\SupplierStatement;
use App\Support\FinanceAudit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Suppliers. The party itself is the shared record (name, VAT number, terms); this controller adds what Purchases needs on top of it:
 * defaults for new bills, early-payment terms, bank details under change control, balances and the statement.
 */
class SupplierController extends PurchasesController
{
    private const ADDRESS = ['line1', 'line2', 'suburb', 'city', 'province', 'postal_code', 'country'];

    public function __construct(private readonly SupplierBankDetails $bank) {}

    public function index(Request $request, ApLedger $ap): View
    {
        $this->authorize('viewAny', Party::class);

        $v = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'archived' => ['nullable', 'boolean']]);
        $archived = (bool) ($v['archived'] ?? false);

        $suppliers = Party::query()->suppliers()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->when(trim((string) ($v['q'] ?? '')) !== '', function (Builder $q) use ($v) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($v['q'])).'%';
                $q->where(fn (Builder $w) => $w->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '\\'", [$like])->orWhereRaw("LOWER(tax_number) LIKE LOWER(?) ESCAPE '\\'", [$like]));
            })
            ->where(fn ($q) => $q->whereNull('external_ref')->orWhere('external_ref', 'not like', 'claimant:%'))
            ->orderBy('name')->paginate(25)->withQueryString();

        $balances = $ap->openItems($this->ledger())->groupBy('party_id')->map(fn ($items) => $items->sum('open_base'));

        return view('purchases.suppliers.index', ['suppliers' => $suppliers, 'balances' => $balances, 'filters' => $v + ['q' => null], 'archived' => $archived, 'canCreate' => $this->gate(Capability::ManageParties)]);
    }

    public function create(): View
    {
        $this->requireCapability(Capability::ManageParties);

        return view('purchases.suppliers.form', $this->formData(new Party(['type' => 'supplier', 'payment_terms_days' => 30]), null));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireCapability(Capability::ManageParties);
        [$party, $profile] = $this->validated($request);

        $created = DB::transaction(function () use ($party, $profile) {
            $p = Party::query()->create($this->ledger()->ownerAttributes() + $party + ['type' => 'supplier', 'status' => 'active']);
            SupplierProfile::query()->create($this->ledger()->ownerAttributes() + $profile + ['party_id' => $p->id]);

            return $p;
        });

        return redirect()->route('purchases.suppliers.show', $created)->with('status', 'Saved. Add their bank details when you have checked them.');
    }

    public function show(Party $party, ApLedger $ap): View
    {
        $this->supplierOnly($party);
        $this->authorize('view', $party);

        $active = $this->bank->active($party);
        $pending = $this->bank->pending($party);

        return view('purchases.suppliers.show', [
            'party' => $party, 'profile' => SupplierProfile::query()->where('party_id', $party->id)->first(), 'items' => $ap->openItems($this->ledger(), null, $party->id),
            'bills' => Bill::query()->where('party_id', $party->id)->orderByDesc('bill_date')->orderByDesc('id')->limit(15)->get(),
            'payments' => Payment::query()->where('party_id', $party->id)->where('direction', 'out')->orderByDesc('paid_on')->orderByDesc('id')->limit(10)->get(),
            'active' => $active, 'pending' => $pending, 'history' => $this->bank->history($party),
            'shared' => ($pending ?? $active) ? $this->bank->sharedWith($pending ?? $active) : collect(),
            'canApproveBank' => $pending !== null && Gate::allows(Capability::ApprovePayment->value) && ($pending->requested_by !== auth()->id() || $this->bank->isSoleMember($this->ledger())),
            'canRequestBank' => $this->gate(Capability::ManageParties), 'canManage' => $this->gate(Capability::ManageParties), 'canBill' => $this->gate(Capability::EnterBill),
            'canPay' => $this->gate(Capability::RecordPayment),
        ]);
    }

    public function edit(Party $party): View
    {
        $this->supplierOnly($party);
        $this->requireCapability(Capability::ManageParties);

        return view('purchases.suppliers.form', $this->formData($party, SupplierProfile::query()->where('party_id', $party->id)->first()));
    }

    public function update(Request $request, Party $party): RedirectResponse
    {
        $this->supplierOnly($party);
        $this->requireCapability(Capability::ManageParties);
        [$data, $profile] = $this->validated($request, $party);

        DB::transaction(function () use ($party, $data, $profile) {
            $party->update($data);
            SupplierProfile::query()->updateOrCreate(['party_id' => $party->id], $this->ledger()->ownerAttributes() + $profile);
        });

        return redirect()->route('purchases.suppliers.show', $party)->with('status', 'Saved.');
    }

    public function statement(Request $request, Party $party, SupplierStatement $statements): View|StreamedResponse
    {
        $this->supplierOnly($party);
        $this->authorize('view', $party);

        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'format' => ['nullable', Rule::in(['csv'])]]);
        $to = CarbonImmutable::parse($v['to'] ?? now()->toDateString());
        $from = CarbonImmutable::parse($v['from'] ?? $to->subMonths(3)->toDateString());
        abort_if($from->greaterThan($to), 422);

        $statement = $statements->forSupplier($party, $from, $to);

        if (($v['format'] ?? null) === 'csv') {
            FinanceAudit::exported($this->ledger()->ownerAttributes(), 'Supplier statement (CSV)', ['report' => 'supplier-statement', 'format' => 'csv', 'party_id' => $party->id, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

            return Response::streamDownload(function () use ($statement) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Supplier statement', $statement['party']->name, 'From '.$statement['from'], 'To '.$statement['to']]);
                fputcsv($out, ['Date', 'Currency', 'What', 'Reference', 'Amount', 'Balance']);
                foreach ($statement['currencies'] as $currency => $c) {
                    fputcsv($out, [$statement['from'], $currency, 'Opening balance', '', '', Money::toDecimalString($c['opening'])]);
                    foreach ($c['lines'] as $line) {
                        fputcsv($out, [$line['date'], $currency, $line['kind'], $line['reference'], Money::toDecimalString($line['amount']), Money::toDecimalString($line['balance'])]);
                    }
                    fputcsv($out, [$statement['to'], $currency, 'Closing balance', '', '', Money::toDecimalString($c['closing'])]);
                }
                fclose($out);
            }, 'statement-'.str($party->name)->slug().'-'.$statement['to'].'.csv', ['Content-Type' => 'text/csv']);
        }

        return view('purchases.suppliers.statement', ['statement' => $statement, 'party' => $party, 'from' => $from, 'to' => $to]);
    }

    public function editBank(Party $party): View
    {
        $this->supplierOnly($party);
        $this->requireCapability(Capability::ManageParties);

        return view('purchases.suppliers.bank', ['party' => $party, 'active' => $this->bank->active($party), 'pending' => $this->bank->pending($party)]);
    }

    public function storeBank(Request $request, Party $party): RedirectResponse
    {
        $this->supplierOnly($party);
        $this->requireCapability(Capability::ManageParties);

        $v = $request->validate([
            'bank_name' => ['required', 'string', 'max:120'], 'account_holder' => ['required', 'string', 'max:120'], 'account_number' => ['required', 'string', 'max:30'],
            'branch_code' => ['nullable', 'string', 'max:15'], 'account_type' => ['nullable', 'string', 'max:30'], 'swift' => ['nullable', 'string', 'max:20'], 'iban' => ['nullable', 'string', 'max:40'],
            'change_reason' => ['required', 'string', 'min:5', 'max:500'], 'verified_by_callback' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(function () use ($request, $party, $v) {
            $row = $this->bank->request($this->ledger(), $this->user($request), $party, $v, $v['change_reason'], (bool) ($v['verified_by_callback'] ?? false));

            return redirect()->route('purchases.suppliers.show', $party)->with('status', $row->isActive()
                ? 'Bank details saved and active.'
                : 'The change is waiting for approval by someone who may approve payments. Until then payments use the details that were already approved, and this supplier is held out of payment runs.');
        });
    }

    public function approveBank(Request $request, SupplierBankDetail $detail): RedirectResponse
    {
        $this->requireCapability(Capability::ApprovePayment);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->attempt(function () use ($request, $detail, $note) {
            $this->bank->approve($this->user($request), $detail, $note);

            return redirect()->route('purchases.suppliers.show', $detail->party_id)->with('status', 'The new bank details are approved and active.');
        });
    }

    public function rejectBank(Request $request, SupplierBankDetail $detail): RedirectResponse
    {
        $this->requireCapability(Capability::ApprovePayment);
        $note = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];

        return $this->attempt(function () use ($request, $detail, $note) {
            $this->bank->reject($this->user($request), $detail, $note);

            return redirect()->route('purchases.suppliers.show', $detail->party_id)->with('status', 'The change was rejected. The earlier details stay in force.');
        });
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function supplierOnly(Party $party): void
    {
        abort_unless($party->isSupplier(), 404);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function validated(Request $request, ?Party $party = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email:rfc', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
            'tax_number' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9 \-]+$/'], 'registration_number' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')], 'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'default_gl_account_id' => ['nullable', 'integer'], 'default_tax_code' => ['nullable', 'string', 'max:40'],
            'early_payment_discount' => ['nullable', 'regex:/^\d{1,2}(\.\d{1,2})?$/'], 'early_payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'billing_address' => ['nullable', 'array'],
        ];
        foreach (self::ADDRESS as $field) {
            $rules["billing_address.{$field}"] = ['nullable', 'string', 'max:120'];
        }

        if (is_string($request->input('currency'))) {
            $request->merge(['currency' => strtoupper(trim($request->input('currency'))) ?: null]);
        }

        $v = $request->validate($rules);

        $vat = isset($v['tax_number']) ? preg_replace('/\s+/', '', trim($v['tax_number'])) : null;
        if ($vat !== null && $vat !== '' && Party::query()->where('tax_number', $vat)->when($party, fn ($q) => $q->whereKeyNot($party->getKey()))->exists()) {
            throw ValidationException::withMessages(['tax_number' => 'Another active customer or supplier already has this VAT number.']);
        }

        if (! empty($v['default_gl_account_id']) && ! $this->accounts()->contains('id', (int) $v['default_gl_account_id'])) {
            throw ValidationException::withMessages(['default_gl_account_id' => 'Choose an account from this ledger\'s chart.']);
        }

        $address = array_filter($v['billing_address'] ?? [], fn ($x) => $x !== null && trim((string) $x) !== '');

        $partyData = [
            'name' => $v['name'], 'legal_name' => $v['legal_name'] ?? null, 'email' => $v['email'] ?? null, 'phone' => $v['phone'] ?? null,
            'tax_number' => $vat !== '' ? $vat : null, 'registration_number' => $v['registration_number'] ?? null, 'currency' => $v['currency'] ?? null,
            'payment_terms_days' => $v['payment_terms_days'] ?? null, 'billing_address' => $address === [] ? null : array_map('trim', $address),
        ];
        $profile = [
            'default_gl_account_id' => ! empty($v['default_gl_account_id']) ? (int) $v['default_gl_account_id'] : null, 'default_tax_code' => $v['default_tax_code'] ?? null,
            'early_payment_discount_bp' => ! empty($v['early_payment_discount']) ? $this->percentToBp($v['early_payment_discount']) : null,
            'early_payment_days' => ! empty($v['early_payment_days']) ? (int) $v['early_payment_days'] : null,
        ];

        return [$partyData, $profile];
    }

    /** @return array<string, mixed> */
    private function formData(Party $party, ?SupplierProfile $profile): array
    {
        return ['party' => $party, 'profile' => $profile, 'accounts' => $this->accounts(), 'taxCodes' => $this->taxCodes(), 'currencies' => $this->currencies()];
    }
}
