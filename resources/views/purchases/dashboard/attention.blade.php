@php
    $due = \App\Models\Purchases\Bill::query()->with('party')->where('kind', 'bill')->whereIn('status', ['open', 'partially_paid'])->where('due_date', '<=', now()->addDays(7)->toDateString())->orderBy('due_date')->limit(5)->get();
@endphp
@if ($due->isNotEmpty())
<x-fx.panel title="Supplier bills due" meta="Overdue and due within 7 days" flush>
    @include('purchases._bill-rows', ['rows' => $due, 'empty' => '', 'overdue' => false])
    <x-slot name="foot"><a class="fx-link" href="{{ route('purchases.bills.index', ['status' => 'unpaid']) }}">All unpaid bills</a></x-slot>
</x-fx.panel>
@endif
