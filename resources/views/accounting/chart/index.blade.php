<x-app-layout>
<div class="page-pad">
    <h1>Chart of accounts</h1>
    @include('accounting._nav')
    @include('accounting._messages')

    @if ($canManage)<p><a class="dot-btn" href="{{ route('accounting.chart.create') }}">Add an account</a></p>@endif

    @foreach ($classes as $class)
        @continue(! isset($accounts[$class]))
        <h2>{{ ucfirst($class) }}</h2>
        <div class="table-scroll">
            <table>
                <caption class="sr-only">{{ ucfirst($class) }} accounts</caption>
                <thead><tr><th scope="col">Code</th><th scope="col">Name</th><th scope="col">Balance (debit +, credit -)</th><th scope="col">Status</th>@if($canManage)<th scope="col">Actions</th>@endif</tr></thead>
                <tbody>
                @foreach ($accounts[$class] as $account)
                    <tr>
                        <td>{{ $account->code }}</td>
                        <th scope="row">{{ $account->name }}@if($account->isSystem()) <span class="acct-hint">(system)</span>@endif</th>
                        <td>{{ \App\Support\Money::format($balances[$account->id], null, true) }}</td>
                        <td>{{ $account->is_active ? 'Active' : 'Inactive' }}@unless($account->is_postable), report line @endunless</td>
                        @if ($canManage)
                        <td>
                            <a href="{{ route('accounting.chart.edit', $account) }}">Edit</a>
                            @if ($account->is_active && ! $account->isSystem())
                                <form method="POST" action="{{ route('accounting.chart.deactivate', $account) }}" class="acct-inline">@csrf <button type="submit" class="dot-btn">Deactivate</button></form>
                            @elseif (! $account->is_active)
                                <form method="POST" action="{{ route('accounting.chart.activate', $account) }}" class="acct-inline">@csrf <button type="submit" class="dot-btn">Activate</button></form>
                            @endif
                        </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</div>
</x-app-layout>
