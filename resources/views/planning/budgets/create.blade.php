<x-app-layout title="New plan">
<x-fx.page-head title="New plan" sub="Name it and choose the months it covers. You add the amounts next." />
<x-fx.errors />
<form method="POST" action="{{ route('planning.budgets.store') }}" class="fx-form">
    @csrf
    <x-fx.input name="name" label="Name" hint="For example: Budget 2027" />
    <x-fx.input name="starts_on" label="First month" type="date" :value="now()->startOfYear()->addYear()->toDateString()" hint="Any day in the first month." />
    <x-fx.input name="months" label="Number of months" type="number" :value="12" hint="1 to 24. Amounts are in {{ $settings->base_currency }}." />
    <div class="fx-formactions"><x-fx.button variant="primary">Create plan</x-fx.button><x-fx.button :href="route('planning.budgets.index')" variant="ghost">Cancel</x-fx.button></div>
</form>
</x-app-layout>
