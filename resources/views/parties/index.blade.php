<x-app-layout>
    <div class="page-pad">
        <header>
            <h1>Customers and suppliers</h1>
            <p>The people and businesses you sell to and buy from.</p>
            @can('create', \App\Models\Shared\Party::class)
                <p><a href="{{ route('parties.create') }}">Add a customer or supplier</a></p>
            @endcan
        </header>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        <form method="GET" action="{{ route('parties.index') }}" role="search">
            <label for="parties-q">Search</label>
            <input id="parties-q" type="search" name="q" value="{{ $filters['q'] }}" maxlength="100" placeholder="Name, email or VAT number">

            <label for="parties-type">Show</label>
            <select id="parties-type" name="type">
                <option value="">Everyone</option>
                <option value="customer" @selected($filters['type'] === 'customer')>Customers</option>
                <option value="supplier" @selected($filters['type'] === 'supplier')>Suppliers</option>
            </select>

            <label for="parties-archived">
                <input id="parties-archived" type="checkbox" name="archived" value="1" @checked($archived)>
                Archived only
            </label>

            <button type="submit">Filter</button>
        </form>

        @if ($parties->count() > 0)
            <div class="table-scroll">
                <table>
                    <caption class="sr-only">{{ $archived ? 'Archived customers and suppliers' : 'Customers and suppliers' }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Type</th>
                            <th scope="col">Email</th>
                            <th scope="col">VAT number</th>
                            <th scope="col">Terms</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($parties as $party)
                            <tr>
                                <th scope="row"><a href="{{ route('parties.show', $party) }}">{{ $party->name }}</a></th>
                                <td>{{ ['customer' => 'Customer', 'supplier' => 'Supplier', 'both' => 'Customer and supplier'][$party->type] }}</td>
                                <td>{{ $party->email ?? 'Not set' }}</td>
                                <td>{{ $party->tax_number ?? 'Not set' }}</td>
                                <td>{{ $party->payment_terms_days !== null ? $party->payment_terms_days.' days' : 'Not set' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $parties->links() }}
        @else
            <p>
                @if ($archived)
                    No archived customers or suppliers.
                @elseif ($filters['q'] || $filters['type'])
                    Nothing matches that search. Clear the filters to see everyone.
                @else
                    No customers or suppliers yet.
                    @can('create', \App\Models\Shared\Party::class)
                        <a href="{{ route('parties.create') }}">Add the first one.</a>
                    @endcan
                @endif
            </p>
        @endif
    </div>
</x-app-layout>
