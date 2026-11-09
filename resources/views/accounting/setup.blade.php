<x-app-layout title="Switch on accounting">
<x-fx.page-head title="Switch on accounting" sub="Accounting adds a chart of accounts, balanced journals and monthly periods beside your cash ledger. Your cash ledger keeps working exactly as it does now; every transaction is also recorded as a double entry. You can review everything before anything is kept." />

@include('accounting._messages')

<x-fx.steps :current="1" :done="[]" />

<div class="fx-grid fx-grid--form r-stack">
    <x-fx.panel title="Set the basics" meta="Three choices. You can change the chart afterwards; the currency is fixed once the first entry is posted.">
        <form method="POST" action="{{ route('accounting.setup.prepare') }}" class="fx-form">
            @csrf
            <x-fx.input name="base_currency" id="base_currency" label="Base currency" maxlength="3" required autocomplete="off" :value="$defaultCurrency" figure
                hint="All journals are kept in this currency. It cannot change once the first entry is posted. Taken from your largest account." />
            <x-fx.select name="fiscal_year_start_month" id="fiscal_year_start_month" label="Financial year starts in"
                hint="The last month before it is the year-end month, where profit is moved to retained earnings when it is closed.">
                @foreach (range(1, 12) as $m)
                <option value="{{ $m }}" @selected((int) old('fiscal_year_start_month', 1) === $m)>{{ \Carbon\Carbon::create(2000, $m, 1)->format('F') }}</option>
                @endforeach
            </x-fx.select>
            <x-fx.select name="chart_template" id="chart_template" label="Chart of accounts" hint="You can add, rename and renumber accounts afterwards.">
                @foreach ($templates as $key => $label)
                <option value="{{ $key }}" @selected(old('chart_template', $defaultTemplate) === $key)>{{ $label }}</option>
                @endforeach
            </x-fx.select>
            <div class="fx-formactions"><x-fx.button variant="primary" icon="arrow-right">Create the chart and review the mapping</x-fx.button></div>
        </form>
    </x-fx.panel>

    <x-fx.panel title="In plain words">
        <dl class="fx-explain">
            <div><dt><x-fx.gloss term="base_currency">Base currency</x-fx.gloss></dt><dd>The one currency your books add up in. Accounts in other currencies are converted into it.</dd></div>
            <div><dt><x-fx.gloss term="financial_year">Financial year</x-fx.gloss></dt><dd>The twelve months your profit is measured over, usually ending in February for a small South African company.</dd></div>
            <div><dt><x-fx.gloss term="chart_of_accounts">Chart of accounts</x-fx.gloss></dt><dd>The list of labels (bank, sales, rent, VAT) every rand is filed under.</dd></div>
            <div><dt><x-fx.gloss term="double_entry">Double entry</x-fx.gloss></dt><dd>Every movement is written twice, once where the money came from and once where it went, so the books can prove themselves.</dd></div>
        </dl>
    </x-fx.panel>
</div>
</x-app-layout>
