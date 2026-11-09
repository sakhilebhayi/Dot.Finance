<x-app-layout :title="'Edit '.$party->name">
<x-fx.page-head :title="'Edit '.$party->name">
    <x-slot name="actions"><x-fx.button :href="route('parties.show', $party)" icon="arrow-left-right">Back to {{ $party->name }}</x-fx.button></x-slot>
</x-fx.page-head>

<form method="POST" action="{{ route('parties.update', $party) }}" class="fx-stack">
    @csrf
    @method('PUT')
    @include('parties._form')
    <div class="fx-formactions"><x-fx.button variant="primary" icon="check">Save changes</x-fx.button><x-fx.button :href="route('parties.show', $party)">Cancel</x-fx.button></div>
</form>
</x-app-layout>
