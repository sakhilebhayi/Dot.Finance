<x-app-layout title="Tax calendar">
    <x-fx.page-head title="Tax calendar" sub="Generated from the rules and your profile. Weekends and public holidays are handled; see the notes on each date.">
        <x-slot name="actions">
            @if ($canManage)<form method="POST" action="{{ route('tax.calendar.generate') }}">@csrf<x-fx.button icon="repeat">Refresh from the rules</x-fx.button></form>@endif
        </x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash />
    <x-fx.errors />

    @unless ($holidaysCovered)
        <div class="fx-alert" role="alert"><x-fx.icon name="circle-alert" /><p>The public-holiday table has no rows for this year, so due dates only skip weekends. Check them against the Government Gazette.</p></div>
    @endunless

    <x-fx.tabs label="Which deadlines" :items="[
        ['label' => 'Open', 'href' => route('tax.calendar.index', ['show' => 'open']), 'current' => $filter === 'open'],
        ['label' => 'History', 'href' => route('tax.calendar.index', ['show' => 'history']), 'current' => $filter === 'history'],
        ['label' => 'All', 'href' => route('tax.calendar.index', ['show' => 'all']), 'current' => $filter === 'all'],
    ]" />

    <x-fx.panel title="Deadlines" :meta="$deadlines->count().' shown'" flush>
        @if ($deadlines->isEmpty())
            <x-fx.empty title="Nothing here.">{{ $filter === 'open' ? 'No open deadlines. Refresh the calendar after changing the profile.' : 'Nothing has been filed or set aside yet.' }}</x-fx.empty>
        @else
        <x-fx.table caption="Tax deadlines in date order">
            <thead><tr><th scope="col">What</th><th scope="col">Period</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col">How sure</th></tr></thead>
            <tbody>
            @foreach ($deadlines as $d)
                @php $s = $d->displayStatus($today); @endphp
                <tr>
                    <td data-label="What" class="is-lead"><a class="fx-link" href="{{ route('tax.calendar.show', $d) }}">{{ $d->label() }}</a>@if($d->adjustment_note)<span class="fx-cell-sub">{{ \Illuminate\Support\Str::limit($d->adjustment_note, 110) }}</span>@endif</td>
                    <td data-label="Period">{{ $d->periodText() }}</td>
                    <td data-label="Due"><span class="fx-cellbody">{{ $d->due_date->format('D j M Y') }}@if($d->payment_due_date && ! $d->payment_due_date->equalTo($d->due_date))<span class="fx-cell-sub">payment outside eFiling {{ $d->payment_due_date->format('D j M') }}</span>@endif</span></td>
                    <td data-label="Status"><x-fx.pill :kind="$s === 'overdue' ? 'variance' : (in_array($s, ['submitted', 'paid'], true) ? 'ticked' : 'plain')">{{ ucfirst($s) }}</x-fx.pill></td>
                    <td data-label="How sure">{{ ucfirst($d->confidence) }}</td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        @endif
    </x-fx.panel>

    <x-fx.panel title="Public holidays used" meta="Derived from the Public Holidays Act, not from a published list">
        @if ($holidays->isEmpty())<p class="fx-hint">No holidays on record for the coming months.</p>@else
        <p class="fx-hint">{{ $holidays->first()->note }} {{ $holidays->every(fn ($h) => $h->verified) ? 'These are verified.' : 'They are NOT verified.' }}</p>
        <ul class="fx-list">@foreach ($holidays as $h)<li>{{ $h->holiday_date->format('D j F Y') }}, {{ $h->name }}</li>@endforeach</ul>
        @endif
    </x-fx.panel>
    @include('tax._footer')
</x-app-layout>
