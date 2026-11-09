@php $p = $profile; $a = $p->address ?? []; @endphp
<x-app-layout title="Business profile">
<x-fx.page-head title="Business profile" sub="How your business appears on quotes, invoices and statements, and the defaults new documents start from." />
<x-fx.flash /><x-fx.errors />
<form method="POST" action="{{ route('sales.profile.update') }}" class="fx-grid fx-grid--form">
    @csrf @method('PUT')
    <div class="fx-stack">
        <x-fx.panel title="Who you are">
            <div class="fx-form">
                <div class="fx-row fx-row--2">
                    <x-fx.input name="legal_name" id="pf-legal" label="Legal name" maxlength="255" :value="$p->legal_name" hint="Printed as the supplier on tax invoices." />
                    <x-fx.input name="trading_name" id="pf-trading" label="Trading name" optional maxlength="255" :value="$p->trading_name" />
                </div>
                <x-fx.check name="vat_registered" id="pf-vat" :checked="(bool) old('vat_registered', $p->vat_registered)">This business is registered for VAT and charges it</x-fx.check>
                <div class="fx-row fx-row--2">
                    <x-fx.input name="vat_number" id="pf-vatno" label="VAT number" optional maxlength="30" :value="$p->vat_number" hint="Required on every tax invoice when you charge VAT." />
                    <x-fx.input name="registration_number" id="pf-reg" label="Company registration number" optional maxlength="50" :value="$p->registration_number" />
                </div>
                <div class="fx-row fx-row--2">
                    <x-fx.input name="address[line1]" id="pf-a1" label="Address" maxlength="120" :value="$a['line1'] ?? null" />
                    <x-fx.input name="address[line2]" id="pf-a2" label="Address line 2" optional maxlength="120" :value="$a['line2'] ?? null" />
                </div>
                <div class="fx-row fx-row--3">
                    <x-fx.input name="address[suburb]" id="pf-sub" label="Suburb" optional maxlength="120" :value="$a['suburb'] ?? null" />
                    <x-fx.input name="address[city]" id="pf-city" label="City" maxlength="120" :value="$a['city'] ?? null" />
                    <x-fx.input name="address[province]" id="pf-prov" label="Province" optional maxlength="120" :value="$a['province'] ?? null" />
                </div>
                <div class="fx-row fx-row--3">
                    <x-fx.input name="address[postal_code]" id="pf-post" label="Postal code" optional maxlength="20" :value="$a['postal_code'] ?? null" />
                    <x-fx.input name="address[country]" id="pf-country" label="Country" maxlength="120" :value="$a['country'] ?? 'South Africa'" />
                    <x-fx.input name="email" id="pf-email" type="email" label="E-mail on documents" optional :value="$p->email" />
                </div>
                <div class="fx-row fx-row--2"><x-fx.input name="phone" id="pf-phone" label="Phone" optional :value="$p->phone" /><x-fx.input name="website" id="pf-web" label="Website" optional :value="$p->website" /></div>
            </div>
        </x-fx.panel>
        <x-fx.panel title="How customers pay you" meta="Printed on invoices">
            <div class="fx-form">
                <div class="fx-row fx-row--2"><x-fx.input name="bank_name" id="pf-bank" label="Bank" optional :value="$p->bank_name" /><x-fx.input name="bank_account_holder" id="pf-holder" label="Account holder" optional :value="$p->bank_account_holder" /></div>
                <div class="fx-row fx-row--3">
                    <x-fx.input name="bank_account_number" id="pf-acc" label="Account number" optional figure :bind="false" :value="null" hint="{{ $p->maskedAccount() ? 'Saved as '.$p->maskedAccount().'. Leave empty to keep it.' : 'Stored encrypted.' }}" />
                    <x-fx.input name="bank_branch_code" id="pf-branch" label="Branch code" optional :value="$p->bank_branch_code" />
                    <x-fx.input name="bank_account_type" id="pf-type" label="Account type" optional :value="$p->bank_account_type" />
                </div>
                <x-fx.textarea name="payment_instructions" id="pf-instr" label="Other payment instructions" optional rows="2" :value="$p->payment_instructions" />
                <p class="sx-hint">{{ $gateway->label() }}. Online card or EFT payment is not available: a person records each payment when it arrives.</p>
            </div>
        </x-fx.panel>
        <x-fx.panel title="Defaults for new documents">
            <div class="fx-form">
                <div class="fx-row fx-row--3">
                    <x-fx.input name="default_terms_days" id="pf-terms" type="number" min="0" max="365" label="Payment terms (days)" :value="$p->default_terms_days ?? 30" />
                    <x-fx.input name="quote_validity_days" id="pf-valid" type="number" min="1" max="365" label="Quote valid for (days)" :value="$p->quote_validity_days ?? 30" />
                    <x-fx.input name="brand_color" id="pf-brand" label="Brand colour" optional maxlength="7" placeholder="#1f4e79" :value="$p->brand_color" hint="Six hex digits. A colour too pale for white text is replaced when printing." />
                </div>
                <x-fx.check name="prices_include_tax" id="pf-incl" :checked="(bool) old('prices_include_tax', $p->prices_include_tax)">New documents start with prices that include VAT</x-fx.check>
                <x-fx.textarea name="default_notes" id="pf-notes" label="Notes" optional rows="2" :value="$p->default_notes" />
                <x-fx.textarea name="default_terms" id="pf-dterms" label="Terms" optional rows="2" :value="$p->default_terms" />
                <x-fx.textarea name="invoice_footer" id="pf-foot" label="Footer" optional rows="2" :value="$p->invoice_footer" />
            </div>
        </x-fx.panel>
        <x-fx.panel title="Payment reminders">
            <div class="fx-form">
                <x-fx.check name="reminders_enabled" id="pf-rem" :checked="(bool) old('reminders_enabled', $p->reminders_enabled)">Send reminders to customers about unpaid invoices</x-fx.check>
                <div class="fx-row fx-row--2">
                    <x-fx.input name="reminder_before_days" id="pf-before" type="number" min="0" max="60" label="Days before the due date" :value="$p->reminder_before_days ?? 3" hint="0 sends none before." />
                    <x-fx.input name="reminder_after_days" id="pf-after" label="Days after the due date" :value="implode(', ', $p->reminderAfterDays())" hint="For example 7, 14, 30. Each is sent once." />
                </div>
                <p class="sx-hint">Each customer can be opted out on their page. Reminders are handed to the mail system; whether they arrive is not known. <a class="fx-link" href="{{ route('sales.reminders.index') }}">Reminder log</a></p>
            </div>
        </x-fx.panel>
    </div>
    <aside class="fx-stack">
        <div class="sx-savebar"><x-fx.button variant="primary" icon="check">Save profile</x-fx.button></div>
        <x-fx.panel title="Numbers">
            <ul class="sx-links">@foreach (\App\Services\Sales\SalesProfiles::SERIES as $type => $prefix)<li><span>{{ ucfirst(str_replace('_', ' ', $type)) }}</span><span>Next {{ $sequences->has($type) ? app(\App\Services\Shared\Numbering::class)->peek($type) : $prefix.'-00001' }}</span></li>@endforeach</ul>
            <p class="sx-hint">Numbers are given when a document is issued, in order, with no gaps. A voided number stays on record.</p>
        </x-fx.panel>
    </aside>
</form>
<x-fx.panel title="Logo">
    <form method="POST" action="{{ route('sales.profile.logo') }}" enctype="multipart/form-data" class="fx-form">@csrf
        @if ($p->logo_path)<img class="sx-logo" src="{{ route('sales.profile.logo.show') }}" alt="Your logo">@endif
        <x-fx.input name="logo" id="pf-logo" type="file" label="PNG or JPEG, up to 1 MB" accept="image/png,image/jpeg" />
        <x-fx.button>Upload logo</x-fx.button>
    </form>
</x-fx.panel>
</x-app-layout>
