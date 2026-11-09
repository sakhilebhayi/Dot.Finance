<x-app-layout :title="$account->exists ? 'Edit account '.$account->code : 'Add an account'">
<x-fx.page-head :title="$account->exists ? 'Edit account '.$account->code : 'Add an account'" sub="An account is a label that money is filed under. Pick the class carefully: it decides where the account appears in your reports." />

@include('accounting._nav')
@include('accounting._messages')

<div class="fx-grid fx-grid--form r-stack">
    <x-fx.panel title="Account details">
        <form method="POST" action="{{ $account->exists ? route('accounting.chart.update', $account) : route('accounting.chart.store') }}" class="fx-form">
            @csrf
            @if ($account->exists) @method('PUT') @endif
            <div class="fx-row fx-row--2">
                <x-fx.input name="code" id="code" label="Code" required maxlength="20" :value="$account->code" figure hint="Letters, numbers, dots and dashes. Unique in this ledger." />
                <x-fx.input name="name" id="name" label="Name" required maxlength="120" :value="$account->name" />
            </div>
            @if (($locked ?? false))
            <x-fx.input name="class_display" id="class" label="Class" :value="ucfirst($account->class)" disabled :bind="false" hint="The class is fixed once an account has postings or the engine uses it." />
            @else
            <x-fx.select name="class" id="class" label="Class">
                @foreach ($classes as $class)
                <option value="{{ $class }}" @selected(old('class', $account->class) === $class)>{{ ucfirst($class) }}</option>
                @endforeach
            </x-fx.select>
            @endif
            <x-fx.select name="parent_id" id="parent_id" label="Parent account" optional hint="Put it under another account to group it in the chart.">
                <option value="">None</option>
                @foreach ($parents as $parent)
                <option value="{{ $parent->id }}" @selected((int) old('parent_id', $account->parent_id) === $parent->id)>{{ $parent->label() }}</option>
                @endforeach
            </x-fx.select>
            <x-fx.input name="description" id="description" label="Description" optional maxlength="255" :value="$account->description" />
            <div class="fx-formactions">
                <x-fx.button variant="primary" icon="check">Save</x-fx.button>
                <x-fx.button :href="route('accounting.chart.index')">Cancel</x-fx.button>
            </div>
        </form>
    </x-fx.panel>

    <x-fx.panel title="The five classes">
        <dl class="fx-explain">
            <div><dt>Asset</dt><dd>Something the business owns or is owed.</dd></div>
            <div><dt>Liability</dt><dd>Something the business owes.</dd></div>
            <div><dt>Equity</dt><dd>What belongs to the owners.</dd></div>
            <div><dt>Income</dt><dd>Money earned from selling.</dd></div>
            <div><dt>Expense</dt><dd>Money spent running the business.</dd></div>
        </dl>
    </x-fx.panel>
</div>
</x-app-layout>
