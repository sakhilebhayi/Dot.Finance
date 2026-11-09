<x-app-layout title="Add a customer or supplier">
<x-fx.page-head title="Add a customer or supplier" sub="The people and businesses you sell to and buy from. You can change any of this later.">
    <x-slot name="actions"><x-fx.button :href="route('parties.index')">Back to the list</x-fx.button></x-slot>
</x-fx.page-head>

<form method="POST" action="{{ route('parties.store') }}" class="fx-stack">
    @csrf
    @include('parties._form')
    <div class="fx-formactions"><x-fx.button variant="primary" icon="check">Save</x-fx.button><x-fx.button :href="route('parties.index')">Cancel</x-fx.button></div>
</form>
</x-app-layout>
