<x-app-layout>
    <div class="page-pad">
        <header>
            <h1>{{ $party->name }}</h1>
            <p>
                {{ ['customer' => 'Customer', 'supplier' => 'Supplier', 'both' => 'Customer and supplier'][$party->type] }}
                @if ($party->trashed()) &middot; Archived @endif
            </p>
            <p><a href="{{ route('parties.index') }}">Back to the list</a></p>
        </header>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section aria-labelledby="party-details">
            <h2 id="party-details">Details</h2>
            <dl>
                @if ($party->legal_name) <dt>Registered name</dt><dd>{{ $party->legal_name }}</dd> @endif
                <dt>Email</dt><dd>{{ $party->email ?? 'Not set' }}</dd>
                <dt>Phone</dt><dd>{{ $party->phone ?? 'Not set' }}</dd>
                <dt>VAT number</dt><dd>{{ $party->tax_number ?? 'Not set' }}</dd>
                <dt>Company registration</dt><dd>{{ $party->registration_number ?? 'Not set' }}</dd>
                <dt>Usual currency</dt><dd>{{ $party->currency ?? 'Not set' }}</dd>
                <dt>Payment terms</dt><dd>{{ $party->payment_terms_days !== null ? $party->payment_terms_days.' days' : 'Not set' }}</dd>
                <dt>Credit limit</dt><dd>{{ $party->credit_limit_cents !== null ? \App\Support\Money::format((int) $party->credit_limit_cents, $party->currency) : 'No limit' }}</dd>
                <dt>Your reference</dt><dd>{{ $party->external_ref ?? 'Not set' }}</dd>
            </dl>

            @foreach (['billing_address' => 'Billing address', 'shipping_address' => 'Delivery address'] as $which => $label)
                @if ($party->{$which})
                    <h3>{{ $label }}</h3>
                    <address>
                        @foreach ($party->{$which} as $line)
                            {{ $line }}<br>
                        @endforeach
                    </address>
                @endif
            @endforeach

            @if ($party->notes)
                <h3>Notes</h3>
                <p>{{ $party->notes }}</p>
            @endif
        </section>

        <section aria-labelledby="party-documents">
            <h2 id="party-documents">Documents</h2>

            @forelse ($files as $item)
                <p>
                    {{ $item['file']->original_name }}
                    ({{ number_format($item['file']->size / 1024, 0) }} KB, added {{ $item['file']->created_at->toDateString() }})
                    @if ($item['file']->isInline())
                        <a href="{{ $item['url'] }}" target="_blank" rel="noopener">Open</a>
                    @endif
                    <a href="{{ $item['download'] }}">Download</a>
                </p>
            @empty
                <p>No documents yet.</p>
            @endforelse

            @can('attach', $party)
                <form method="POST" action="{{ route('parties.attachments.store', $party) }}" enctype="multipart/form-data">
                    @csrf
                    <label for="party-file">Add a document (PDF, image, CSV, Excel or Word, up to {{ round(config('shared.attachments.max_bytes') / 1048576) }} MB)</label>
                    <input id="party-file" type="file" name="file" required>
                    <label for="party-dup"><input id="party-dup" type="checkbox" name="allow_duplicate" value="1"> Keep it even if the same file is already stored</label>
                    <button type="submit">Upload</button>
                </form>
            @endcan
        </section>

        <section aria-labelledby="party-actions">
            <h2 id="party-actions">Manage</h2>
            @if ($party->trashed())
                @can('restore', $party)
                    <form method="POST" action="{{ route('parties.restore', $party) }}">
                        @csrf
                        <button type="submit">Restore</button>
                    </form>
                @endcan
            @else
                @can('update', $party)
                    <p><a href="{{ route('parties.edit', $party) }}">Edit details</a></p>
                @endcan
                @can('delete', $party)
                    <form method="POST" action="{{ route('parties.destroy', $party) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit">Archive</button>
                        <small>Archiving hides them from lists and pickers but keeps every invoice, bill and payment that points at them.</small>
                    </form>
                @endcan
            @endif
        </section>
    </div>
</x-app-layout>
