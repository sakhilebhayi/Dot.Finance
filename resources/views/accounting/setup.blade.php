<x-app-layout>
<div class="page-pad">
    <h1>Switch on accounting</h1>
    <p>Accounting adds a chart of accounts, balanced journals and monthly periods beside your cash ledger. Your cash ledger keeps working exactly as it does now; every transaction is also recorded as a double entry. You can review everything before anything is kept.</p>
    @include('accounting._messages')

    <form method="POST" action="{{ route('accounting.setup.prepare') }}" class="dot-card acct-form">
        @csrf
        <p>
            <label for="base_currency">Base currency</label>
            <input id="base_currency" name="base_currency" class="dot-input" maxlength="3" required value="{{ old('base_currency', $defaultCurrency) }}" autocomplete="off">
            <span class="acct-hint">All journals are kept in this currency. It cannot change once the first entry is posted. Taken from your largest account.</span>
        </p>
        <p>
            <label for="fiscal_year_start_month">Financial year starts in</label>
            <select id="fiscal_year_start_month" name="fiscal_year_start_month" class="dot-input">
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" @selected((int) old('fiscal_year_start_month', 1) === $m)>{{ \Carbon\Carbon::create(2000, $m, 1)->format('F') }}</option>
                @endforeach
            </select>
            <span class="acct-hint">The last month before it is the year-end month, where profit is moved to retained earnings when it is closed.</span>
        </p>
        <p>
            <label for="chart_template">Chart of accounts</label>
            <select id="chart_template" name="chart_template" class="dot-input">
                @foreach ($templates as $key => $label)
                    <option value="{{ $key }}" @selected(old('chart_template', $defaultTemplate) === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <span class="acct-hint">You can add, rename and renumber accounts afterwards.</span>
        </p>
        <p><button type="submit" class="dot-btn">Create the chart and review the mapping</button></p>
    </form>
</div>
</x-app-layout>
