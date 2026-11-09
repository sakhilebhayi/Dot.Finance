<x-app-layout title="Accountant pack">
    <x-fx.page-head title="Accountant pack" sub="A zip of CSV files: trial balance, general ledger, the working papers, adjustments, payroll totals, the document index. Every file says what it is." />
    @include('tax._tabs')
    <x-fx.errors />
    @if (! $canExport)
        <x-fx.empty title="Your role cannot export.">Ask a finance manager or accountant for the pack.</x-fx.empty>
    @else
    <x-fx.panel title="Build the pack">
        <form method="POST" action="{{ route('tax.pack.download') }}" class="fx-form fx-stack">@csrf
            <div class="fx-row fx-row--2"><x-fx.input name="from" label="From" type="date" :value="$defaultFrom" /><x-fx.input name="to" label="To" type="date" :value="$defaultTo" /></div>
            @if ($canDetail)<x-fx.check name="payroll_detail">Include per-employee payroll detail (personal information)</x-fx.check>@else<p class="fx-hint">Per-employee payroll detail is left out: your role cannot see it.</p>@endif
            <p class="fx-hint">The pack is marked PREPARED, NOT SUBMITTED and names the rule versions used. {{ \App\Services\Tax\TaxBasis::DISCLAIMER }}</p>
            <div class="fx-formactions"><x-fx.button variant="primary" icon="download">Download the pack</x-fx.button></div>
        </form>
    </x-fx.panel>
    @endif
</x-app-layout>
