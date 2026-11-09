@if ($rows->isEmpty())
    <x-fx.empty :title="$empty" />
@else
<x-fx.table caption="Bills">
    <thead><tr><th scope="col">Bill</th><th scope="col">Supplier</th><th scope="col">Due</th><th scope="col" class="is-num">Total</th></tr></thead>
    <tbody>
    @foreach ($rows as $bill)
        <tr>
            <td data-label="Bill" class="is-lead"><a class="fx-link" href="{{ route('purchases.bills.show', $bill) }}">{{ $bill->displayNumber() }}</a></td>
            <td data-label="Supplier">{{ $bill->party?->name }}</td>
            <td data-label="Due">{{ $bill->due_date?->format('j M Y') }}</td>
            <td data-label="Total" class="is-num"><x-fx.money :cents="$bill->total_cents" :currency="$bill->currency" :variance="$overdue" /></td>
        </tr>
    @endforeach
    </tbody>
</x-fx.table>
@endif
