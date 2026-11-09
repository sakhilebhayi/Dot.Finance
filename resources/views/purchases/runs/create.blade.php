<x-app-layout title="New payment run">
<x-fx.page-head title="New payment run" sub="Bills and claims due by the date, grouped by supplier. A supplier whose bank details are being changed is held out until the change is approved." />
@include('purchases._nav')
<x-fx.errors />
<form method="GET" action="{{ route('purchases.runs.create') }}" class="fx-row fx-row--3">
    <x-fx.input name="due_by" id="run-dueby" type="date" label="Due by" :value="$dueBy->toDateString()" />
    <div class="fx-formactions"><x-fx.button>Show what is due</x-fx.button></div>
</form>
@if ($groups->isEmpty())
<x-fx.empty title="Nothing is due by {{ $dueBy->format('j M Y') }}.">Pick a later date, or post the bills that are still drafts.</x-fx.empty>
@else
<form method="POST" action="{{ route('purchases.runs.store') }}" class="fx-form">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ $token }}">
    <input type="hidden" name="due_by" value="{{ $dueBy->toDateString() }}">
    <x-fx.panel title="What to pay" flush>
        <x-fx.table caption="Bills due, by supplier">
            <thead><tr><th scope="col"><span class="sr-only">Include</span></th><th scope="col">Supplier</th><th scope="col">Document</th><th scope="col">Due</th><th scope="col" class="is-num">Owed</th></tr></thead>
            <tbody>
            @foreach ($groups as $g)
                @foreach ($g['items'] as $n => $item)
                <tr>
                    <td data-label="Include">@if (! $g['held'])<x-fx.check name="pick[]" :value="$item['type'].'-'.$item['id']" :checked="true"><span class="sr-only">Pay {{ $item['number'] }}</span></x-fx.check>@endif</td>
                    <td data-label="Supplier" class="is-lead">{{ $n === 0 ? $g['party']->name : '' }}@if ($n === 0 && $g['held'])<div class="fx-cell-sub">{{ $g['held'] }}</div>@endif</td>
                    <td data-label="Document">{{ $item['number'] }}</td>
                    <td data-label="Due">{{ \Carbon\Carbon::parse($item['due'])->format('j M Y') }}</td>
                    <td data-label="Owed" class="is-num"><x-fx.money :cents="$item['open']" :currency="$baseCurrency" /></td>
                </tr>
                @endforeach
            @endforeach
            </tbody>
        </x-fx.table>
    </x-fx.panel>
    <x-fx.panel title="The run">
        <div class="fx-row fx-row--3">
            <x-fx.input name="name" id="run-name" label="Name" optional maxlength="120" />
            <x-fx.select name="cash_account_id" id="run-account" label="Paid from" required>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</x-fx.select>
            <x-fx.input name="paid_on" id="run-paidon" type="date" label="Pays on" :value="now()->toDateString()" required />
        </div>
    </x-fx.panel>
    <div class="fx-formactions"><x-fx.button variant="primary">Create the run</x-fx.button><x-fx.button :href="route('purchases.runs.index')">Cancel</x-fx.button></div>
</form>
@endif
</x-app-layout>
