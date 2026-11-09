<x-app-layout>
<div class="page-pad">
    <h1>Review before accounting is switched on</h1>
    <p>Base currency <strong>{{ $settings->base_currency }}</strong>, financial year starts in {{ \Carbon\Carbon::create(2000, $settings->fiscal_year_start_month, 1)->format('F') }}, chart: {{ \App\Services\Accounting\ChartTemplates::label($settings->chart_template ?? 'personal') }}.</p>
    @include('accounting._messages')

    @if ($foreign->isNotEmpty())
        <p class="acct-warning" role="note">
            {{ $foreign->count() }} account(s) are not in {{ $settings->base_currency }} ({{ $foreign->pluck('name')->join(', ') }}).
            Their balances need exchange rates to be converted. Until a rate source is connected, only amounts whose rate is known (a transfer to or from a {{ $settings->base_currency }} account) can be posted; the rest appear as exceptions below and accounting cannot be switched on while they leave the books out of step.
        </p>
    @endif

    <form method="POST" action="{{ route('accounting.setup.mapping') }}" class="dot-card acct-form">
        @csrf
        <h2>Cash accounts</h2>
        <table>
            <caption class="sr-only">Which GL account each cash account posts to</caption>
            <thead><tr><th scope="col">Cash account</th><th scope="col">Currency</th><th scope="col">Balance</th><th scope="col">GL account</th></tr></thead>
            <tbody>
            @foreach ($accounts as $account)
                <tr>
                    <th scope="row">{{ $account->name }}</th>
                    <td>{{ $account->currency }}</td>
                    <td>{{ \App\Support\Money::format($account->balanceCents()) }}</td>
                    <td>
                        <label class="sr-only" for="map-account-{{ $account->id }}">GL account for {{ $account->name }}</label>
                        <select id="map-account-{{ $account->id }}" name="accounts[{{ $account->id }}]" class="dot-input">
                            @foreach ($assetLike as $gl)
                                <option value="{{ $gl->id }}" @selected($account->gl_account_id === $gl->id)>{{ $gl->label() }}</option>
                            @endforeach
                        </select>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <h2>Categories</h2>
        <table>
            <caption class="sr-only">Which GL account each category posts to</caption>
            <thead><tr><th scope="col">Category</th><th scope="col">Type</th><th scope="col">GL account</th></tr></thead>
            <tbody>
            @foreach ($categories as $category)
                <tr>
                    <th scope="row">{{ $category->name }}</th>
                    <td>{{ ucfirst($category->type) }}</td>
                    <td>
                        <label class="sr-only" for="map-category-{{ $category->id }}">GL account for {{ $category->name }}</label>
                        <select id="map-category-{{ $category->id }}" name="categories[{{ $category->id }}]" class="dot-input">
                            @foreach ($pnlLike as $gl)
                                <option value="{{ $gl->id }}" @selected($category->gl_account_id === $gl->id)>{{ $gl->label() }}</option>
                            @endforeach
                        </select>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p>Anything without a category posts to Uncategorised income or expense.</p>
        <p><button type="submit" class="dot-btn">Save the mapping</button></p>
    </form>

    <section class="dot-card acct-form">
        <h2>Dry run</h2>
        <p>Computes every entry the history would produce, checks that each cash account ties out to the cent, then throws it all away. Nothing is kept.</p>
        <form method="POST" action="{{ route('accounting.setup.dry-run') }}">@csrf <button type="submit" class="dot-btn">Run the dry run</button></form>

        @if ($dryRun)
            <h3>Last dry run, {{ $dryRun->created_at->diffForHumans() }}</h3>
            @include('accounting._run', ['run' => $dryRun])
        @endif
    </section>

    <section class="dot-card acct-form">
        <h2>Apply</h2>
        @if ($rowCount > $webLimit)
            <p>This ledger has {{ number_format($rowCount) }} transactions. Run it from the server: <code>php artisan finance:gl:backfill --ledger={{ \App\Support\Ledger\LedgerContext::current()->key() }} --apply --enable</code></p>
        @else
            <p>Posts the history, verifies it, and only if every check passes switches accounting on. If a check fails, accounting stays off and the result is shown here.</p>
            <form method="POST" action="{{ route('accounting.setup.apply') }}">@csrf <button type="submit" class="dot-btn">Post the history and switch accounting on</button></form>
        @endif

        @if ($applyRun)
            <h3>Last apply, {{ $applyRun->created_at->diffForHumans() }}</h3>
            @include('accounting._run', ['run' => $applyRun])
        @endif
    </section>
</div>
</x-app-layout>
