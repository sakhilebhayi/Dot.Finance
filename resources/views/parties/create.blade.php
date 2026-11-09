<x-app-layout title="Add a customer or supplier">
<x-fx.page-head title="Add a customer or supplier" sub="The people and businesses you sell to and buy from. You can change any of this later.">
    <x-slot name="actions"><x-fx.button :href="route('parties.index')">Back to the list</x-fx.button></x-slot>
</x-fx.page-head>

<form method="POST" action="{{ route('parties.store') }}" class="fx-stack">
    @csrf
    @if (session('similar_parties'))
    <section class="fx-panel fx-panel__body fx-notice" role="alert" aria-labelledby="similar-title">
        <h2 id="similar-title" class="fx-panel__title">This looks like someone already on your list</h2>
        <ul>
            @foreach (session('similar_parties') as $s)
            <li><a class="fx-link" href="{{ route('parties.show', $s['id']) }}">{{ $s['name'] }}</a> ({{ $s['type'] }}), because it has {{ $s['reason'] }}.</li>
            @endforeach
        </ul>
        <p>Two records for one business split its balance, its statements and its credit limit. Open the existing one, or tick the box below if this really is a different business.</p>
        <x-fx.check name="confirm_duplicate" id="p-confirm-duplicate" value="1">Add it anyway: this is a different business</x-fx.check>
    </section>
    @endif
    @include('parties._form')
    <div class="fx-formactions"><x-fx.button variant="primary" icon="check">Save</x-fx.button><x-fx.button :href="route('parties.index')">Cancel</x-fx.button></div>
</form>
</x-app-layout>
