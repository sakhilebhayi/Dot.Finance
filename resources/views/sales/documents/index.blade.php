@php use App\Support\Money; use Illuminate\Support\Str; $base = \App\Models\Accounting\LedgerSetting::query()->first()?->base_currency; $kindLabel = ['tax_invoice' => 'Invoice', 'debit_note' => 'Debit note', 'quote' => 'Quote', 'pro_forma' => 'Pro forma', 'credit_note' => 'Credit note']; @endphp
<x-app-layout :title="$spec['title']">
<x-fx.page-head :title="$spec['title']" :sub="$spec['sub']">
    <x-slot name="actions">
        @if ($canCreate && $group === 'invoices')<x-fx.button variant="primary" icon="plus" :href="route('sales.invoices.create')">New invoice</x-fx.button>@endif
        @if ($canCreate && $group === 'quotes')<x-fx.button variant="primary" icon="plus" :href="route('sales.quotes.create')">New quote</x-fx.button>@endif
    </x-slot>
</x-fx.page-head>
@include('sales._tabs', ['active' => $group])
<x-fx.flash />

@if ($summary && ($summary['open_count'] > 0 || $summary['credit_base_cents'] > 0))
<section class="sx-strip" aria-label="What is owed">
    <div class="sx-strip__item"><span class="sx-strip__label">Owed to you</span><x-fx.money :cents="$summary['net_base_cents']" lead :currency="$base" />@if ($summary['credit_base_cents'] > 0)<span class="sx-strip__note">{{ Money::format($summary['owed_base_cents'], $base) }} on {{ $summary['open_count'] }} open {{ Str::plural('invoice', $summary['open_count']) }}, less {{ Money::format($summary['credit_base_cents'], $base) }} of customer credit</span>@endif</div>
    <div class="sx-strip__item"><span class="sx-strip__label">Overdue ({{ $summary['overdue_count'] }})</span><x-fx.money :cents="$summary['overdue_base_cents']" :variance="$summary['overdue_base_cents'] > 0" /></div>
    <div class="sx-strip__item"><span class="sx-strip__label">Falls due in 7 days ({{ $summary['due_soon_count'] }})</span><x-fx.money :cents="$summary['due_soon_base_cents']" /></div>
    <div class="sx-strip__item sx-strip__item--proof">
        @include('sales._books-proof', ['summary' => $summary])
    </div>
</section>
@endif

<form method="GET" action="{{ url()->current() }}" class="fx-filters" role="search" aria-label="Filter {{ strtolower($spec['title']) }}">
    <x-fx.field label="Search" for="f-q"><x-dot.filter-search name="q" id="f-q" label="Search" :value="$filters['q']" placeholder="Number, reference or customer" /></x-fx.field>
    <x-fx.select name="status" id="f-status" label="Status">
        <option value="">Any</option>
        @foreach (($group === 'quotes' ? ['draft' => 'draft', 'issued' => 'sent', 'accepted' => 'accepted', 'declined' => 'declined', 'expired' => 'expired', 'void' => 'void'] : ($group === 'invoices' ? ['draft' => 'draft', 'awaiting_approval' => 'awaiting approval', 'open' => 'open (owing)', 'overdue' => 'overdue', 'paid' => 'paid', 'void' => 'void'] : ['draft' => 'draft', 'issued' => 'issued', 'void' => 'void'])) as $value => $label)
            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ ucfirst($label) }}</option>
        @endforeach
    </x-fx.select>
    <x-fx.select name="party" id="f-party" label="Customer"><option value="">Everyone</option>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected((string) $filters['party'] === (string) $c->id)>{{ $c->name }}</option>@endforeach</x-fx.select>
    <x-fx.input name="from" id="f-from" type="date" label="From" :value="$filters['from']" />
    <x-fx.input name="to" id="f-to" type="date" label="To" :value="$filters['to']" />
    <div class="fx-filters__go"><x-fx.button>Filter</x-fx.button></div>
</form>

<x-fx.panel flush>
@if ($documents->count() > 0)
    <x-fx.table :caption="$spec['title']">
        <thead><tr>
            <th scope="col">Number</th><th scope="col">Customer</th><th scope="col">Date</th><th scope="col">{{ $group === 'quotes' ? 'Valid until' : 'Due' }}</th>
            <th scope="col">Status</th><th scope="col" class="is-num">Total</th>@if ($group !== 'quotes')<th scope="col" class="is-num">{{ $group === 'credit-notes' ? 'Corrects' : 'Owing' }}</th>@endif
        </tr></thead>
        <tbody>
        @foreach ($documents as $d)
            <tr>
                <td data-label="Number" class="is-lead"><a class="fx-cell-main fx-link" href="{{ route('sales.documents.show', $d) }}">{{ $d->number ?? 'Draft' }}</a><div class="fx-cell-sub">{{ $kindLabel[$d->kind] }}{{ $d->reference ? ' · '.$d->reference : '' }}</div></td>
                <td data-label="Customer">{{ $d->party?->name }}</td>
                <td data-label="Date">{{ $d->issue_date->format('j M Y') }}</td>
                <td data-label="{{ $group === 'quotes' ? 'Valid until' : 'Due' }}">{{ ($group === 'quotes' ? $d->valid_until : $d->due_date)?->format('j M Y') ?? '–' }}</td>
                <td data-label="Status"><x-sales.status :document="$d" :credited="$d->isReceivable() && $d->isSealed() && isset($balances[$d->id]) && $balances[$d->id] === 0 && $d->status === 'issued'" /></td>
                <td data-label="Total" class="is-num"><x-fx.money :cents="$d->total_cents" :currency="$d->currency" /></td>
                @if ($group === 'invoices')
                <td data-label="Owing" class="is-num">@isset($balances[$d->id])<x-fx.money :cents="$balances[$d->id]" :currency="$d->currency" :variance="$balances[$d->id] > 0 && $d->isOverdue()" />@else<span class="sx-dash">–</span>@endisset</td>
                @elseif ($group === 'credit-notes')
                <td data-label="Corrects" class="is-num">{{ $d->parent?->number ?? '–' }}</td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    <div class="fx-panel__foot">{{ $documents->links() }}</div>
@else
    <x-fx.empty :title="array_filter($filters) ? 'Nothing matches those filters.' : 'No '.strtolower($spec['title']).' yet.'">
        @if (array_filter($filters))Clear the filters to see everything.@elseif ($group === 'invoices')An invoice is posted to the books when you issue it, and gets the next number in your sequence.@elseif ($group === 'quotes')A quote reaches no books until you turn it into an invoice.@else A credit note is made from an issued invoice, on its page.@endif
        <x-slot name="action">@if ($canCreate && $group === 'invoices')<x-fx.button variant="primary" icon="plus" :href="route('sales.invoices.create')">Create the first invoice</x-fx.button>@endif @if ($canCreate && $group === 'quotes')<x-fx.button variant="primary" icon="plus" :href="route('sales.quotes.create')">Create the first quote</x-fx.button>@endif</x-slot>
    </x-fx.empty>
@endif
</x-fx.panel>
</x-app-layout>
