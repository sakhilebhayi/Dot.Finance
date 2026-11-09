<x-app-layout title="Journals">
@php $filtered = ! empty($filters['source']) || ! empty($filters['from']) || ! empty($filters['to']); @endphp

<x-fx.page-head title="Journals" sub="Every entry in the books, newest first. Entries appear as you record transactions, transfers and imports, or when you post a manual one.">
    <x-slot name="actions">
        @if ($canPost)<x-fx.button variant="primary" :href="route('accounting.journals.create')" icon="plus">New manual entry</x-fx.button>@endif
    </x-slot>
</x-fx.page-head>

@include('accounting._nav')
@include('accounting._messages')

<details class="fx-panel fx-collapse" open data-fx-collapse-mobile data-active="{{ $filtered ? '1' : '0' }}">
    <summary><span>Filters @if ($filtered) <x-fx.pill kind="plain">on</x-fx.pill>@endif</span><x-fx.icon name="chevron-down" /></summary>
    <form method="GET" action="{{ route('accounting.journals.index') }}" class="fx-filters" role="search" aria-label="Filter journal entries">
        <x-fx.select name="source" id="source" label="Source">
            <option value="">All</option>
            @foreach ($sources as $s)<option value="{{ $s }}" @selected(($filters['source'] ?? null) === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
        </x-fx.select>
        <x-fx.input name="from" id="from" type="date" label="From" :value="$filters['from'] ?? ''" :bind="false" />
        <x-fx.input name="to" id="to" type="date" label="To" :value="$filters['to'] ?? ''" :bind="false" />
        <div class="fx-filters__actions">
            <x-fx.button variant="primary">Filter</x-fx.button>
            @if ($filtered)<x-fx.button :href="route('accounting.journals.index')">Clear</x-fx.button>@endif
        </div>
    </form>
</details>

<x-fx.panel flush>
    @if ($entries->isEmpty())
    <x-fx.empty title="No journal entries match.">No journal entries match. Entries appear as you record transactions, transfers and imports, or post a manual entry.</x-fx.empty>
    @else
    <x-fx.table caption="Journal entries, newest first">
        <thead>
            <tr>
                <th scope="col">Entry</th>
                <th scope="col">Date</th>
                <th scope="col">Memo</th>
                <th scope="col">Source</th>
                <th scope="col" class="is-num">Amount</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entries as $entry)
            <tr>
                <th scope="row" data-label="Entry" class="is-lead"><a href="{{ route('accounting.journals.show', $entry) }}" class="fx-link"><span class="fx-figure">{{ $entry->reference() }}</span></a></th>
                <td data-label="Date"><span class="fx-figure">{{ $entry->entry_date->toDateString() }}</span></td>
                <td data-label="Memo">{{ $entry->memo }}</td>
                <td data-label="Source">{{ ucfirst(str_replace('_', ' ', $entry->source_type)) }}</td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="$entry->total_cents" :currency="$entry->currency" :trace="route('accounting.journals.show', $entry)" trace-label="Open {{ $entry->reference() }}" /></td>
                <td data-label="Status">
                    @if ($entry->isReversed())<x-fx.pill icon="history">Reversed</x-fx.pill>
                    @elseif ($entry->isReversal())<x-fx.pill icon="history">Reversal</x-fx.pill>
                    @else<x-fx.pill kind="plain">Posted</x-fx.pill>@endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
    @endif
</x-fx.panel>
@if ($entries->hasPages()){{ $entries->links() }}@endif
</x-app-layout>
