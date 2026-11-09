<x-app-layout title="VAT">
    <x-fx.page-head title="VAT" sub="VAT201 returns, built from the tax codes on your journal lines. Prepared here, filed by a person on SARS eFiling.">
        <x-slot name="actions"><x-fx.button :href="route('tax.vat.review')" icon="list-checks">Review queue</x-fx.button></x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash /><x-fx.errors />

    @if (! $profile->vat_registered)
        <x-fx.empty title="This business is not recorded as VAT-registered.">See on the overview whether registration is required, or optional. If you are registered, say so in the tax profile.
            <x-slot name="action"><x-fx.button :href="route('tax.obligations')">What applies</x-fx.button></x-slot>
        </x-fx.empty>
    @elseif (count($rows) === 0)
        <x-fx.empty title="No VAT periods yet.">Add the period category and registration date in the tax profile.</x-fx.empty>
    @else
    <x-fx.panel title="Periods" meta="Category {{ $profile->vat_category }}, {{ $profile->vat_basis }} basis, {{ $profile->vat_filing_channel ?? 'eFiling (default)' }}" flush>
        <x-fx.table caption="VAT periods, newest first">
            <thead><tr><th scope="col">Period</th><th scope="col">Due</th><th scope="col">Return</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td data-label="Period" class="is-lead">{{ $row['label'] }}<span class="fx-cell-sub">{{ $row['period']['start']->format('j M Y') }} to {{ $row['period']['end']->format('j M Y') }}</span></td>
                    <td data-label="Due">@if($row['deadline']){{ $row['deadline']->due_date->format('D j M Y') }}@else <span class="fx-hint">not in the calendar</span>@endif</td>
                    <td data-label="Return">
                        @if ($row['return'])
                            <x-fx.pill :kind="in_array($row['return']->status, ['submitted', 'paid'], true) ? 'ticked' : 'plain'">{{ $row['return']->statusLabel() }}</x-fx.pill>
                            @if ($row['return']->revision > 1)<span class="fx-cell-sub">revision {{ $row['return']->revision }}</span>@endif
                        @else<span class="fx-hint">{{ $row['ended'] ? 'Not started' : 'Period still running' }}</span>@endif
                    </td>
                    <td data-label="" class="is-actions">
                        @if ($row['return'])
                            <x-fx.button size="sm" :href="route('tax.returns.show', $row['return'])">Open</x-fx.button>
                        @elseif ($canPrepare)
                            <form method="POST" action="{{ route('tax.vat.start') }}">@csrf<input type="hidden" name="period_start" value="{{ $row['period']['start']->toDateString() }}"><x-fx.button size="sm" variant="primary">{{ $row['ended'] ? 'Prepare' : 'Preview' }}</x-fx.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
    </x-fx.panel>
    @endif
    @include('tax._footer')
</x-app-layout>
