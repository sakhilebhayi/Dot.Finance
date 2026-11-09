<x-app-layout>
    <div class="page-pad">
        <header>
            <h1>Add a customer or supplier</h1>
            <p><a href="{{ route('parties.index') }}">Back to the list</a></p>
        </header>

        <form method="POST" action="{{ route('parties.store') }}">
            @csrf
            @include('parties._form')
            <p><button type="submit">Save</button></p>
        </form>
    </div>
</x-app-layout>
