<x-app-layout title="Recurring">

<x-fx.page-head title="Recurring">
    <x-slot name="sub">Income and expenses that repeat. Each period the scheduler adds a normal entry to your ledger. Editing one only changes what is added from then on; entries already in the ledger stay as they are. @can('create', \App\Models\Transaction::class)Add one by ticking "Repeat" when you <a href="{{ route('transactions.create') }}">add a transaction</a>.@endcan</x-slot>
</x-fx.page-head>

<x-fx.flash />

<x-fx.panel flush>
    @if ($rules->count() > 0)
    <x-fx.table caption="Repeating income and expenses">
        <thead>
            <tr>
                <th scope="col">Description</th>
                <th scope="col">Account</th>
                <th scope="col" class="is-num">Amount</th>
                <th scope="col">Repeats</th>
                <th scope="col">Next</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rules as $rule)
            @php $status = $rule->status(); @endphp
            <tr>
                <td data-label="Description" class="is-lead"><span class="fx-cell-main">{{ $rule->description }}</span>@if ($rule->category)<div class="fx-cell-sub">{{ $rule->category->name }}</div>@endif</td>
                <td data-label="Account">{{ $rule->account?->name ?? '—' }}</td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="$rule->type === 'income' ? $rule->amount_cents : -$rule->amount_cents" :currency="$rule->currency" :signed="true" /></td>
                <td data-label="Repeats">{{ $rule->cadenceLabel() }}@if ($rule->end_date)<div class="fx-cell-sub">until {{ $rule->end_date->format('j M Y') }}</div>@endif</td>
                <td data-label="Next" class="fx-date">{{ $rule->next_occurrence_date?->format('Y-m-d') ?? '—' }}</td>
                <td data-label="Status">
                    @if ($status === 'active')<x-fx.pill icon="repeat">Active</x-fx.pill>
                    @elseif ($status === 'paused')<x-fx.pill icon="pause">Paused</x-fx.pill>
                    @else<x-fx.pill icon="ban">Ended</x-fx.pill>@endif
                </td>
                <td class="is-actions">
                    @cannot('update', $rule)
                    <span class="fx-hint">read-only</span>
                    @elseif ($status !== 'ended')
                    <a href="{{ route('recurring.edit', $rule) }}" class="fx-link">Edit<span class="sr-only"> {{ $rule->description }}</span></a>
                    @if ($status === 'paused')
                    <form method="POST" action="{{ route('recurring.resume', $rule) }}">@csrf <button type="submit" class="fx-linkbtn">Resume<span class="sr-only"> {{ $rule->description }}</span></button></form>
                    @else
                    <form method="POST" action="{{ route('recurring.pause', $rule) }}">@csrf <button type="submit" class="fx-linkbtn">Pause<span class="sr-only"> {{ $rule->description }}</span></button></form>
                    @endif
                    <form method="POST" action="{{ route('recurring.stop', $rule) }}" data-fx-confirm="Stop this recurring item? Nothing more will be added after today." data-fx-confirm-label="Stop it">@csrf <button type="submit" class="fx-linkbtn fx-linkbtn--danger">Stop<span class="sr-only"> {{ $rule->description }}</span></button></form>
                    @else
                    <span class="fx-hint">finished</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
    @else
    <x-fx.empty title="Nothing repeats yet.">Tick "Repeat" when you add a transaction and it will appear here.
        <x-slot name="action">@can('create', \App\Models\Transaction::class)<x-fx.button :href="route('transactions.create')" icon="plus">Add transaction</x-fx.button>@endcan</x-slot>
    </x-fx.empty>
    @endif
</x-fx.panel>

</x-app-layout>
