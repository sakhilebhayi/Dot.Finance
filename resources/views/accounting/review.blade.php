<x-app-layout title="Review before accounting is switched on">
@php
    $dryOk = $dryRun && (bool) ($dryRun->report['verify']['ok'] ?? false);
    $done = array_values(array_filter([1, $dryRun ? 2 : null, $dryOk ? 3 : null]));
    $current = $dryOk ? 4 : ($dryRun ? 3 : 2);
    $baseCurrency = $settings->base_currency;
@endphp

<x-fx.page-head title="Review before accounting is switched on">
    <x-slot name="sub">Base currency <strong>{{ $baseCurrency }}</strong>, financial year starts in {{ \Carbon\Carbon::create(2000, $settings->fiscal_year_start_month, 1)->format('F') }}, chart: {{ \App\Services\Accounting\ChartTemplates::label($settings->chart_template ?? 'personal') }}. Nothing is kept until the last step.</x-slot>
</x-fx.page-head>

@include('accounting._messages')

<x-fx.steps :current="$current" :done="$done" />

@if ($foreign->isNotEmpty())
<div class="fx-notice" role="note">
    <x-fx.icon name="info" />
    <p>
        {{ $foreign->count() }} account(s) are not in {{ $baseCurrency }} ({{ $foreign->pluck('name')->join(', ') }}).
        Their balances need <x-fx.gloss term="exchange_rate">exchange rates</x-fx.gloss> to be converted. Until a rate source is connected, only amounts whose rate is known (a transfer to or from a {{ $baseCurrency }} account) can be posted; the rest appear as exceptions below and accounting cannot be switched on while they leave the books out of step.
    </p>
</div>
@endif

<x-fx.panel id="step-2" title="Match your accounts" meta="Step 2 of 4. Say which books account each of your accounts and categories belongs to.">
    <form method="POST" action="{{ route('accounting.setup.mapping') }}" class="fx-form">
        @csrf
        <p class="fx-prose">Each cash account you already keep gets its own account in the books, so the two can be compared to the cent. Each category is filed under an income or expense account. The suggestions below are usually right; change one only if you know it belongs elsewhere.</p>

        <div class="fx-mapgroup">
            <h3 class="fx-subhead">Cash accounts</h3>
            <x-fx.table caption="Which GL account each cash account posts to" class="fx-table--compact">
                <thead><tr><th scope="col">Cash account</th><th scope="col">Currency</th><th scope="col" class="is-num">Balance</th><th scope="col">Books account</th></tr></thead>
                <tbody>
                @foreach ($accounts as $account)
                    <tr>
                        <th scope="row" data-label="Cash account" class="is-lead"><span class="fx-cell-main">{{ $account->name }}</span></th>
                        <td data-label="Currency"><span class="fx-figure">{{ $account->currency }}</span></td>
                        <td data-label="Balance" class="is-num"><x-fx.money :cents="$account->balanceCents()" :currency="$account->currency" /></td>
                        <td data-label="Books account">
                            <label class="sr-only" for="map-account-{{ $account->id }}">GL account for {{ $account->name }}</label>
                            <select id="map-account-{{ $account->id }}" name="accounts[{{ $account->id }}]" class="fx-select">
                                @foreach ($assetLike as $gl)
                                <option value="{{ $gl->id }}" @selected($account->gl_account_id === $gl->id)>{{ $gl->label() }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-fx.table>
        </div>

        <div class="fx-mapgroup">
            <h3 class="fx-subhead">Categories</h3>
            <x-fx.table caption="Which GL account each category posts to" class="fx-table--compact">
                <thead><tr><th scope="col">Category</th><th scope="col">Type</th><th scope="col">Books account</th></tr></thead>
                <tbody>
                @foreach ($categories as $category)
                    <tr>
                        <th scope="row" data-label="Category" class="is-lead"><span class="fx-cell-main">{{ $category->name }}</span></th>
                        <td data-label="Type">{{ ucfirst($category->type) }}</td>
                        <td data-label="Books account">
                            <label class="sr-only" for="map-category-{{ $category->id }}">GL account for {{ $category->name }}</label>
                            <select id="map-category-{{ $category->id }}" name="categories[{{ $category->id }}]" class="fx-select">
                                @foreach ($pnlLike as $gl)
                                <option value="{{ $gl->id }}" @selected($category->gl_account_id === $gl->id)>{{ $gl->label() }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-fx.table>
        </div>

        <p class="fx-hint">Anything without a category posts to Uncategorised income or expense.</p>
        <div class="fx-formactions"><x-fx.button variant="primary" icon="check">Save the mapping</x-fx.button></div>
    </form>
</x-fx.panel>

<x-fx.panel id="step-3" title="Try it first" meta="Step 3 of 4. A dry run. Nothing is kept.">
    <div class="fx-stack">
        <p class="fx-prose">Computes every <x-fx.gloss term="journal_entry">journal entry</x-fx.gloss> the history would produce, checks that each cash account ties out to the cent, then throws it all away. Nothing is kept.</p>
        <form method="POST" action="{{ route('accounting.setup.dry-run') }}">@csrf <x-fx.button variant="{{ $dryRun ? 'secondary' : 'primary' }}" icon="scan-search">Run the dry run</x-fx.button></form>

        @if ($dryRun)
        <section aria-labelledby="dry-run-title" class="fx-resultbox">
            <h3 class="fx-subhead" id="dry-run-title">Last dry run, {{ $dryRun->created_at->diffForHumans() }}</h3>
            @include('accounting._run', ['run' => $dryRun])
        </section>
        @endif
    </div>
</x-fx.panel>

<x-fx.panel id="step-4" title="Switch it on" meta="Step 4 of 4. This keeps the history and turns accounting on.">
    <div class="fx-stack">
        @if ($rowCount > $webLimit)
        <p class="fx-prose">This ledger has {{ number_format($rowCount) }} transactions. Run it from the server: <code class="fx-code">php artisan finance:gl:backfill --ledger={{ \App\Support\Ledger\LedgerContext::current()->key() }} --apply --enable</code></p>
        @else
        <p class="fx-prose">Posts the history, verifies it, and only if every check passes switches accounting on. If a check fails, accounting stays off and the result is shown here.</p>
        <form method="POST" action="{{ route('accounting.setup.apply') }}" data-fx-confirm="This posts your whole transaction history into the books and switches accounting on if every check passes. If a check fails, accounting stays off." data-fx-confirm-label="Post and switch on">
            @csrf
            <x-fx.button variant="primary" icon="book-check">Post the history and switch accounting on</x-fx.button>
        </form>
        @endif

        @if ($applyRun)
        <section aria-labelledby="apply-run-title" class="fx-resultbox">
            <h3 class="fx-subhead" id="apply-run-title">Last apply, {{ $applyRun->created_at->diffForHumans() }}</h3>
            @include('accounting._run', ['run' => $applyRun])
        </section>
        @endif
    </div>
</x-fx.panel>
</x-app-layout>
