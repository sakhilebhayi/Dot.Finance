<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Shared\Currency;
use App\Models\Shared\Party;
use App\Rules\MoneyAmount;
use App\Services\Shared\Attachments;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Customers and suppliers of the active ledger. Every query goes through Party's ledger scope, so another
 * ledger's party is a 404 before the policy runs. Parties are archived, never destroyed.
 */
class PartyController extends Controller
{
    private const ADDRESS_FIELDS = ['line1', 'line2', 'suburb', 'city', 'province', 'postal_code', 'country'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Party::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['customer', 'supplier'])],
            'archived' => ['nullable', 'boolean'],
        ]);

        $archived = (bool) ($validated['archived'] ?? false);

        $parties = Party::query()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->when($validated['type'] ?? null, fn (Builder $q, string $type) => $type === 'customer' ? $q->customers() : $q->suppliers())
            ->when(trim((string) ($validated['q'] ?? '')) !== '', function (Builder $q) use ($validated) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($validated['q'])).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(legal_name) LIKE LOWER(?) ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(tax_number) LIKE LOWER(?) ESCAPE '\\'", [$like]));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('parties.index', ['parties' => $parties, 'filters' => $validated + ['q' => null, 'type' => null], 'archived' => $archived]);
    }

    public function create(): View
    {
        $this->authorize('create', Party::class);

        return view('parties.create', ['currencies' => $this->currencies(), 'party' => new Party(['type' => 'customer'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Party::class);

        $data = $this->validated($request);

        $party = Party::create($this->ledger()->ownerAttributes() + $data + ['status' => 'active']);

        return redirect()->route('parties.show', $party)->with('status', 'Saved. '.$party->name.' is now in your '.($party->isCustomer() && $party->isSupplier() ? 'customers and suppliers' : ($party->isCustomer() ? 'customers' : 'suppliers')).'.');
    }

    public function show(Party $party, Attachments $attachments): View
    {
        $this->authorize('view', $party);

        $files = $party->attachments()->whereNull('archived_at')->latest('id')->get()
            ->map(fn ($file) => ['file' => $file, 'url' => $attachments->url($file, inline: true), 'download' => $attachments->url($file)]);

        return view('parties.show', ['party' => $party, 'files' => $files]);
    }

    public function edit(Party $party): View
    {
        $this->authorize('update', $party);

        return view('parties.edit', ['party' => $party, 'currencies' => $this->currencies()]);
    }

    public function update(Request $request, Party $party): RedirectResponse
    {
        $this->authorize('update', $party);

        $party->update($this->validated($request, $party));

        return redirect()->route('parties.show', $party)->with('status', 'Saved.');
    }

    /** Archive: hidden from pickers and lists, kept for the records that point at it. */
    public function destroy(Party $party): RedirectResponse
    {
        $this->authorize('delete', $party);

        DB::transaction(function () use ($party) {
            $party->update(['status' => 'archived']);
            $party->delete();
        });

        return redirect()->route('parties.index')->with('status', $party->name.' was archived. You can restore it from the archived list.');
    }

    public function restore(Party $party): RedirectResponse
    {
        $this->authorize('restore', $party);

        try {
            DB::transaction(function () use ($party) {
                $party->restore();
                $party->update(['status' => 'active']);
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['party' => 'Another active customer or supplier already uses this tax number or reference, so this one cannot be restored until that is changed.']);
        }

        return redirect()->route('parties.show', $party)->with('status', 'Restored.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Party $party = null): array
    {
        $rules = [
            'type' => ['required', Rule::in(Party::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'tax_number' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'credit_limit' => ['nullable', new MoneyAmount(allowZero: true)],
            'external_ref' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'billing_address' => ['nullable', 'array'],
            'shipping_address' => ['nullable', 'array'],
        ];
        foreach (self::ADDRESS_FIELDS as $field) {
            $rules["billing_address.{$field}"] = ['nullable', 'string', 'max:120'];
            $rules["shipping_address.{$field}"] = ['nullable', 'string', 'max:120'];
        }

        // 'usd' is as good as 'USD': tidy before checking against the currency list
        if (is_string($request->input('currency'))) {
            $request->merge(['currency' => strtoupper(trim($request->input('currency'))) ?: null]);
        }

        $validated = $request->validate($rules);

        // The two identifiers the database keeps unique among live parties: say so before it has to.
        foreach (['tax_number' => 'tax number', 'external_ref' => 'reference'] as $field => $label) {
            $value = $field === 'tax_number' && isset($validated[$field]) ? preg_replace('/\s+/', '', trim($validated[$field])) : ($validated[$field] ?? null);

            if ($value !== null && $value !== '' && Party::query()->where($field, $value)->when($party, fn (Builder $q) => $q->whereKeyNot($party->getKey()))->exists()) {
                throw ValidationException::withMessages([$field => "Another active customer or supplier already has this {$label}."]);
            }
        }

        $validated['credit_limit_cents'] = isset($validated['credit_limit']) && $validated['credit_limit'] !== '' ? Money::parse((string) $validated['credit_limit']) : null;
        unset($validated['credit_limit']);

        foreach (['billing_address', 'shipping_address'] as $address) {
            $filled = array_filter($validated[$address] ?? [], fn ($v) => $v !== null && trim((string) $v) !== '');
            $validated[$address] = $filled === [] ? null : array_intersect_key(array_map('trim', $filled), array_flip(self::ADDRESS_FIELDS));
        }

        return $validated;
    }

    /** @return Collection<int, Currency> */
    private function currencies()
    {
        return Currency::query()->where('is_active', true)->orderBy('code')->get();
    }
}
