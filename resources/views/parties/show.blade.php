<x-app-layout :title="$party->name">
@php
    $typeLabel = ['customer' => 'Customer', 'supplier' => 'Supplier', 'both' => 'Customer and supplier'][$party->type];
    $hasTerms = $party->payment_terms_days !== null || $party->credit_limit_cents !== null || $party->currency;
@endphp

<x-fx.page-head :title="$party->name">
    <x-slot name="sub">{{ $typeLabel }}@if ($party->legal_name), registered as {{ $party->legal_name }}@endif @if ($party->trashed()) <x-fx.pill icon="ban">Archived</x-fx.pill> @endif</x-slot>
    <x-slot name="actions">
        <x-fx.button :href="route('parties.index')" icon="arrow-left-right">Back to the list</x-fx.button>
        @unless ($party->trashed())
        @can('update', $party)<x-fx.button variant="primary" :href="route('parties.edit', $party)" icon="pencil">Edit details</x-fx.button>@endcan
        @endunless
    </x-slot>
</x-fx.page-head>

<x-fx.flash />
<x-fx.errors />

@if ($hasTerms)
<dl class="fx-facts" aria-label="Terms at a glance">
    <div><dt><x-fx.gloss term="payment_terms">Payment terms</x-fx.gloss></dt><dd>{{ $party->payment_terms_days !== null ? $party->payment_terms_days.' days' : 'Not set' }}</dd></div>
    <div><dt><x-fx.gloss term="credit_limit">Credit limit</x-fx.gloss></dt><dd>{{ $party->credit_limit_cents !== null ? \App\Support\Money::format((int) $party->credit_limit_cents, $party->currency) : 'No limit' }}</dd></div>
    <div><dt>Usual currency</dt><dd>{{ $party->currency ?? 'Not set' }}</dd></div>
</dl>
@endif

<div class="fx-grid fx-grid--lead r-stack">
    <div class="fx-stack">
        <x-fx.panel title="Details" id="party-details">
            <dl class="fx-kv">
                @if ($party->legal_name)<div><dt>Registered name</dt><dd>{{ $party->legal_name }}</dd></div>@endif
                <div><dt>Email</dt><dd>@if ($party->email)<a href="mailto:{{ $party->email }}" class="fx-link">{{ $party->email }}</a>@else<span class="fx-quiet">Not set</span>@endif</dd></div>
                <div><dt>Phone</dt><dd>{{ $party->phone ?? 'Not set' }}</dd></div>
                <div><dt><x-fx.gloss term="vat_registration">VAT number</x-fx.gloss></dt><dd>@if ($party->tax_number)<span class="fx-figure">{{ $party->tax_number }}</span>@else<span class="fx-quiet">Not set</span>@endif</dd></div>
                <div><dt>Company registration</dt><dd>@if ($party->registration_number)<span class="fx-figure">{{ $party->registration_number }}</span>@else<span class="fx-quiet">Not set</span>@endif</dd></div>
                <div><dt>Usual currency</dt><dd>{{ $party->currency ?? 'Not set' }}</dd></div>
                <div><dt>Payment terms</dt><dd>{{ $party->payment_terms_days !== null ? $party->payment_terms_days.' days' : 'Not set' }}</dd></div>
                <div><dt>Credit limit</dt><dd>{{ $party->credit_limit_cents !== null ? \App\Support\Money::format((int) $party->credit_limit_cents, $party->currency) : 'No limit' }}</dd></div>
                <div><dt>Your reference</dt><dd>{{ $party->external_ref ?? 'Not set' }}</dd></div>
            </dl>
        </x-fx.panel>

        @php $addresses = collect(['billing_address' => 'Billing address', 'shipping_address' => 'Delivery address'])->filter(fn ($label, $which) => $party->{$which}); @endphp
        @if ($addresses->isNotEmpty())
        <div class="fx-grid fx-grid--even r-stack">
            @foreach ($addresses as $which => $label)
            <x-fx.panel :title="$label">
                <address class="fx-address">
                    @foreach ($party->{$which} as $line)
                    {{ $line }}<br>
                    @endforeach
                </address>
            </x-fx.panel>
            @endforeach
        </div>
        @endif

        @if ($party->notes)
        <x-fx.panel title="Notes"><p class="fx-prose">{{ $party->notes }}</p></x-fx.panel>
        @endif

        @stack('party_activity')
    </div>

    <div class="fx-stack">
        <x-fx.panel title="Documents" id="party-documents" meta="Contracts, agreements and anything else worth keeping with them">
            @if ($files->isEmpty())
            <x-fx.empty title="No documents yet.">No documents yet.</x-fx.empty>
            @else
            <ul class="fx-lines">
                @foreach ($files as $item)
                <li class="fx-line fx-line--split">
                    <x-fx.icon name="file-text" class="fx-line__icon" />
                    <div class="fx-line__main">
                        <p class="fx-line__title">{{ $item['file']->original_name }}</p>
                        <p class="fx-line__sub">{{ number_format($item['file']->size / 1024, 0) }} KB, added <span class="fx-figure">{{ $item['file']->created_at->toDateString() }}</span></p>
                    </div>
                    <div class="fx-line__side">
                        @if ($item['file']->isInline())<x-fx.button size="sm" :href="$item['url']" target="_blank" rel="noopener" icon="external-link">Open<span class="sr-only"> {{ $item['file']->original_name }}</span></x-fx.button>@endif
                        <x-fx.button size="sm" :href="$item['download']" icon="download">Download<span class="sr-only"> {{ $item['file']->original_name }}</span></x-fx.button>
                    </div>
                </li>
                @endforeach
            </ul>
            @endif
            <p class="fx-hint">Open and Download use a link that works for a short time and only for people in this ledger.</p>

            @can('attach', $party)
            <form method="POST" action="{{ route('parties.attachments.store', $party) }}" enctype="multipart/form-data" class="fx-form fx-form--ruled">
                @csrf
                <x-fx.input name="file" id="party-file" type="file" label="Add a document" required hint="PDF, image, CSV, Excel or Word, up to {{ round(config('shared.attachments.max_bytes') / 1048576) }} MB" />
                <x-fx.check name="allow_duplicate" id="party-dup" >Keep it even if the same file is already stored</x-fx.check>
                <div class="fx-formactions"><x-fx.button variant="primary" icon="file-up">Upload</x-fx.button></div>
            </form>
            @endcan
        </x-fx.panel>

        @if ($party->trashed())
            @can('restore', $party)
            <x-fx.panel title="Archived" id="party-actions" meta="Hidden from lists and pickers. Every invoice, bill and payment that points at them is kept.">
                <form method="POST" action="{{ route('parties.restore', $party) }}">@csrf <x-fx.button variant="primary" icon="play">Restore</x-fx.button></form>
            </x-fx.panel>
            @endcan
        @else
            @can('delete', $party)
            <x-fx.panel title="Manage" id="party-actions">
                <form method="POST" action="{{ route('parties.destroy', $party) }}" class="fx-form" data-fx-confirm="Archive {{ $party->name }}? They disappear from lists and pickers, but every invoice, bill and payment that points at them is kept, and you can restore them." data-fx-confirm-label="Archive">
                    @csrf
                    @method('DELETE')
                    <p class="fx-hint">Archiving hides them from lists and pickers but keeps every invoice, bill and payment that points at them.</p>
                    <div class="fx-formactions"><x-fx.button variant="danger" icon="ban">Archive</x-fx.button></div>
                </form>
            </x-fx.panel>
            @endcan
        @endif
    </div>
</div>
</x-app-layout>
