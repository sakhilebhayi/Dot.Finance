<x-app-layout title="Periods">
@php $closedThrough = $settings->books_closed_through; @endphp

<x-fx.page-head title="Periods" sub="Months close in order and reopen newest first. The last month of the financial year also moves the year's profit to retained earnings." />

@include('accounting._nav')
@include('accounting._messages')

<section class="fx-lock {{ $closedThrough ? 'is-locked' : 'is-open' }}" aria-labelledby="lock-title">
    <x-fx.icon :name="$closedThrough ? 'lock' : 'clock'" class="fx-lock__icon" />
    <div>
        <h2 class="fx-lock__title" id="lock-title">
            @if ($closedThrough)
            Books closed through <span class="fx-figure">{{ $closedThrough->toDateString() }}</span>
            @else
            Books closed through: <strong>nothing yet</strong>
            @endif
        </h2>
        <p>
            @if ($closedThrough)
            Nothing dated on or before that day can be posted, edited or deleted. Anything newer is still open for entries.
            @else
            Every month is still open. Closing a month <x-fx.gloss term="period_close">locks it</x-fx.gloss>: nothing dated in it can be posted, edited or deleted afterwards.
            @endif
        </p>
    </div>
</section>

@if ($canClose)
<x-fx.panel title="Close months" meta="Closes every open month up to and including the one you choose.">
    <form method="POST" action="{{ route('accounting.periods.close-through') }}" class="fx-inline fx-inline--grow" data-fx-confirm="This locks every month up to and including the one you chose. Nothing dated in them can be posted, edited or deleted until you reopen the newest one." data-fx-confirm-label="Close the months">
        @csrf
        <x-fx.input name="through" id="through" type="month" label="Close every month through" required />
        <x-fx.button variant="primary" icon="lock">Close</x-fx.button>
    </form>
</x-fx.panel>
@endif

<x-fx.panel flush title="Months" meta="Newest first">
    @if ($periods->isEmpty())
    <x-fx.empty title="No periods yet.">No periods yet. A month appears here the first time something is posted into it.</x-fx.empty>
    @else
    <x-fx.table caption="Accounting periods, newest first">
        <thead>
            <tr>
                <th scope="col">Period</th>
                <th scope="col">Dates</th>
                <th scope="col">Status</th>
                <th scope="col">Detail</th>
                @if($canClose)<th scope="col"><span class="sr-only">Action</span></th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach ($periods as $period)
            <tr @class(['is-locked' => $period->isClosed()])>
                <th scope="row" data-label="Period" class="is-lead"><span class="fx-cell-main">{{ $period->label() }}</span>@if($period->is_year_end)<div class="fx-cell-sub">financial year-end</div>@endif</th>
                <td data-label="Dates"><span class="fx-nowrap"><span class="fx-figure">{{ $period->start_date->toDateString() }}</span> to <span class="fx-figure">{{ $period->end_date->toDateString() }}</span></span></td>
                <td data-label="Status">
                    @if ($period->isClosed())<x-fx.pill icon="lock">Closed</x-fx.pill>@else<x-fx.pill kind="plain" icon="clock">Open</x-fx.pill>@endif
                </td>
                <td data-label="Detail" @class(['is-blank' => ! $period->isClosed() && ! $period->reopened_at])><span class="fx-cellbody">
                    @if ($period->isClosed()) closed {{ $period->closed_at?->toDayDateTimeString() }}@if($period->closing_entry_id), <a href="{{ route('accounting.journals.show', $period->closing_entry_id) }}" class="fx-link">year-end entry</a>@endif @endif
                    @if ($period->reopened_at) reopened {{ $period->reopened_at->toDayDateTimeString() }}: {{ $period->reopen_reason }}@endif
                </span></td>
                @if ($canClose)
                <td class="is-actions">
                    @if ($period->isClosed())
                    <form method="POST" action="{{ route('accounting.periods.reopen', $period) }}" class="fx-inline" data-fx-confirm="Reopen {{ $period->label() }}? Entries can be posted into it again, and your reason is kept in the record." data-fx-confirm-label="Reopen {{ $period->label() }}">
                        @csrf
                        <label class="sr-only" for="reason-{{ $period->id }}">Reason for reopening {{ $period->label() }}</label>
                        <input id="reason-{{ $period->id }}" name="reason" class="fx-input fx-input--reason" required maxlength="255" placeholder="Reason">
                        <x-fx.button size="sm" icon="play">Reopen</x-fx.button>
                    </form>
                    @else
                    <form method="POST" action="{{ route('accounting.periods.close', $period) }}" data-fx-confirm="Close {{ $period->label() }}? Nothing dated in it can be posted, edited or deleted afterwards." data-fx-confirm-label="Close {{ $period->label() }}">@csrf <x-fx.button size="sm" icon="lock">Close<span class="sr-only"> {{ $period->label() }}</span></x-fx.button></form>
                    @endif
                </td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
    @endif
</x-fx.panel>
</x-app-layout>
