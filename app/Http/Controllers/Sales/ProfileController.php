<?php

namespace App\Http\Controllers\Sales;

use App\Models\Shared\DocumentSequence;
use App\Services\Sales\Gateways\ManualGateway;
use App\Services\Sales\SalesProfiles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The business as it appears on documents: names, VAT registration, address, bank details, logo, defaults, reminders. */
class ProfileController extends SalesController
{
    public function edit(SalesProfiles $profiles): View
    {
        $this->allow('manage-tax');
        $profile = $profiles->for($this->ledger());

        return view('sales.profile.edit', ['profile' => $profile, 'sequences' => DocumentSequence::query()->whereIn('doc_type', array_keys(SalesProfiles::SERIES))->get()->keyBy('doc_type'), 'gateway' => app(ManualGateway::class)]);
    }

    public function update(Request $request, SalesProfiles $profiles): RedirectResponse
    {
        $this->allow('manage-tax');
        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:255'], 'trading_name' => ['nullable', 'string', 'max:255'], 'vat_registered' => ['nullable', 'boolean'],
            'vat_number' => ['nullable', 'string', 'max:30'], 'registration_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'array'], 'address.line1' => ['nullable', 'string', 'max:120'], 'address.line2' => ['nullable', 'string', 'max:120'], 'address.suburb' => ['nullable', 'string', 'max:120'],
            'address.city' => ['nullable', 'string', 'max:120'], 'address.province' => ['nullable', 'string', 'max:120'], 'address.postal_code' => ['nullable', 'string', 'max:20'], 'address.country' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'website' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:100'], 'bank_account_holder' => ['nullable', 'string', 'max:150'], 'bank_account_number' => ['nullable', 'string', 'max:40'],
            'bank_branch_code' => ['nullable', 'string', 'max:20'], 'bank_account_type' => ['nullable', 'string', 'max:30'], 'payment_instructions' => ['nullable', 'string', 'max:1000'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'invoice_footer' => ['nullable', 'string', 'max:1000'], 'default_notes' => ['nullable', 'string', 'max:2000'], 'default_terms' => ['nullable', 'string', 'max:2000'],
            'default_terms_days' => ['required', 'integer', 'min:0', 'max:365'], 'quote_validity_days' => ['required', 'integer', 'min:1', 'max:365'], 'prices_include_tax' => ['nullable', 'boolean'],
            'reminders_enabled' => ['nullable', 'boolean'], 'reminder_before_days' => ['required', 'integer', 'min:0', 'max:60'], 'reminder_after_days' => ['nullable', 'string', 'max:60', 'regex:/^\d{1,3}(\s*,\s*\d{1,3}){0,5}$/'],
        ]);

        $data['vat_registered'] = (bool) ($data['vat_registered'] ?? false);
        $data['prices_include_tax'] = (bool) ($data['prices_include_tax'] ?? false);
        $data['reminders_enabled'] = (bool) ($data['reminders_enabled'] ?? false);
        $data['vat_number'] = isset($data['vat_number']) ? preg_replace('/\s+/', '', $data['vat_number']) : null;
        $data['reminder_after_days'] = isset($data['reminder_after_days']) && $data['reminder_after_days'] !== '' ? array_values(array_unique(array_map('intval', explode(',', $data['reminder_after_days'])))) : [];
        $data['address'] = array_filter($data['address'] ?? [], fn ($v) => $v !== null && trim((string) $v) !== '') ?: null;
        if ($data['vat_registered'] && blank($data['vat_number'])) {
            return back()->withInput()->withErrors(['vat_number' => 'A VAT-registered business needs its VAT number: it must be on every tax invoice.']);
        }
        if (blank($data['bank_account_number'] ?? null)) {
            unset($data['bank_account_number']);   // blank keeps the stored number (it is never shown back in full)
        }

        $profiles->save($this->ledger(), $data, $request->user()->id);

        return redirect()->route('sales.profile.edit')->with('status', 'Business profile saved. Documents already issued keep the details they were issued with.');
    }

    public function logo(Request $request, SalesProfiles $profiles): RedirectResponse
    {
        $this->allow('manage-tax');
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:'.SalesProfiles::LOGO_MAX_KB]]);
        $profiles->storeLogo($this->ledger(), $request->file('logo'));

        return back()->with('status', 'Logo saved.');
    }

    public function showLogo(SalesProfiles $profiles): StreamedResponse
    {
        $this->allow('view-finance');
        $profile = $profiles->for($this->ledger());
        abort_if($profile->logo_path === null || ! Storage::disk($profile->logo_disk)->exists($profile->logo_path), 404);

        return Storage::disk($profile->logo_disk)->response($profile->logo_path, null, ['Content-Type' => $profile->logo_mime, 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
