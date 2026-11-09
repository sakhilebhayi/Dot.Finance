<x-app-layout>
    <div class="page-pad">
        <header>
            <h1>Edit {{ $party->name }}</h1>
            <p><a href="{{ route('parties.show', $party) }}">Back to {{ $party->name }}</a></p>
        </header>

        <form method="POST" action="{{ route('parties.update', $party) }}">
            @csrf
            @method('PUT')
            @include('parties._form')
            <p><button type="submit">Save changes</button></p>
        </form>
    </div>
</x-app-layout>
