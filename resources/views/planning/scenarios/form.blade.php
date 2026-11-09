<x-app-layout :title="$scenario ? 'Edit scenario' : 'New scenario'">
<x-fx.page-head :title="$scenario ? 'Edit scenario' : 'New scenario'" sub="Choose what you want to test. Leave a row on its blank type to ignore it. Amounts are in your ledger's currency; percentages can be negative." />
<x-fx.errors />
@php
    $rows = old('assumptions', $rows);
    $rows = array_values($rows);
    while (count($rows) < 5) { $rows[] = []; }
@endphp
<form method="POST" action="{{ $scenario ? route('planning.scenarios.update', $scenario) : route('planning.scenarios.store') }}" class="fx-form">
    @csrf
    @if ($scenario) @method('PUT') @endif
    <x-fx.input name="name" label="Name" :value="$scenario?->name" />
    <x-fx.select name="horizon" label="Look ahead">
        <option value="weeks_13" @selected(old('horizon', $scenario?->horizon) !== 'months_12')>13 weeks</option>
        <option value="months_12" @selected(old('horizon', $scenario?->horizon) === 'months_12')>12 months</option>
    </x-fx.select>

    @foreach ($rows as $i => $row)
    @php
        $money = fn ($k) => isset($row[$k.'_cents']) ? \App\Support\Money::toDecimalString((int) $row[$k.'_cents']) : ($row[$k] ?? '');
        $percent = isset($row['bp']) ? number_format($row['bp'] / 100, 2, '.', '') : ($row['percent'] ?? '');
    @endphp
    <fieldset class="fx-panel">
        <legend class="fx-section-title">Assumption {{ $i + 1 }}</legend>
        <div class="rp-toolbar">
            <x-fx.select :name="'assumptions['.$i.'][type]'" label="Type" :id="'a'.$i.'-type'">
                <option value="">Not used</option>
                @foreach ($types as $key => $label)<option value="{{ $key }}" @selected(($row['type'] ?? '') === $key)>{{ $label }}</option>@endforeach
            </x-fx.select>
            <x-fx.input :name="'assumptions['.$i.'][days]'" label="Days" type="number" :value="$row['days'] ?? ''" :id="'a'.$i.'-days'" :bind="false" hint="Sooner, later or delayed." />
            <x-fx.input :name="'assumptions['.$i.'][name]'" label="Name" :value="$row['name'] ?? ''" :id="'a'.$i.'-name'" :bind="false" hint="A cost or a one-off." />
            <x-fx.input :name="'assumptions['.$i.'][amount]'" label="Amount" :value="$money('amount')" :id="'a'.$i.'-amount'" :bind="false" figure />
            <x-fx.input :name="'assumptions['.$i.'][start_on]'" label="Starts on" type="date" :value="$row['start_on'] ?? ''" :id="'a'.$i.'-start'" :bind="false" />
            <x-fx.input :name="'assumptions['.$i.'][months]'" label="For months" type="number" :value="$row['months'] ?? ''" :id="'a'.$i.'-months'" :bind="false" hint="Empty: every month." />
            <x-fx.select :name="'assumptions['.$i.'][direction]'" label="Direction" :id="'a'.$i.'-dir'">
                <option value="">Choose</option>
                @foreach (['in' => 'Receipt', 'out' => 'Payment', 'income' => 'Recurring income', 'expense' => 'Recurring expense'] as $k => $l)<option value="{{ $k }}" @selected(($row['direction'] ?? '') === $k)>{{ $l }}</option>@endforeach
            </x-fx.select>
            <x-fx.input :name="'assumptions['.$i.'][on]'" label="On" type="date" :value="$row['on'] ?? ''" :id="'a'.$i.'-on'" :bind="false" />
            <x-fx.input :name="'assumptions['.$i.'][percent]'" label="Change %" :value="$percent" :id="'a'.$i.'-pct'" :bind="false" hint="For recurring items, e.g. 5 or -2.5." />
        </div>
    </fieldset>
    @endforeach

    <div class="fx-formactions">
        <x-fx.button variant="primary">Save scenario</x-fx.button>
        <x-fx.button :href="route('planning.scenarios.index')" variant="ghost">Cancel</x-fx.button>
    </div>
</form>
</x-app-layout>
