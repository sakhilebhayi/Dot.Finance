<x-app-layout title="Employer taxes">
    <x-fx.page-head title="Employer taxes" sub="The monthly EMP201 (PAYE, SDL, UIF, ETI), prepared from the month's payroll summary. A person files it on eFiling.">
        <x-slot name="actions"><x-fx.button :href="route('tax.payroll.index')" icon="users">Payroll summaries</x-fx.button></x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash /><x-fx.errors />

    @if (! $profile->employs_staff)
        <x-fx.empty title="No employer taxes: the profile says there are no employees.">If that has changed, update the tax profile.<x-slot name="action"><x-fx.button :href="route('tax.profile.edit')">Tax profile</x-fx.button></x-slot></x-fx.empty>
    @else
    <x-fx.panel title="Months" flush>
        <x-fx.table caption="EMP201 by month, newest first">
            <thead><tr><th scope="col">Month</th><th scope="col">Return</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
            <tbody>
            @foreach ($months as $m)
                <tr>
                    <td data-label="Month" class="is-lead">{{ $m['month']->format('F Y') }}</td>
                    <td data-label="Return">@if($m['return'])<x-fx.pill :kind="in_array($m['return']->status, ['submitted', 'paid'], true) ? 'ticked' : 'plain'">{{ $m['return']->statusLabel() }}</x-fx.pill>@else<span class="fx-hint">Not started</span>@endif</td>
                    <td data-label="" class="is-actions">
                        @if ($m['return'])<x-fx.button size="sm" :href="route('tax.returns.show', $m['return'])">Open</x-fx.button>
                        @elseif ($canPrepare)<form method="POST" action="{{ route('tax.emp201.start') }}">@csrf<input type="hidden" name="month" value="{{ $m['month']->format('Y-m') }}"><x-fx.button size="sm" variant="primary">Prepare</x-fx.button></form>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
    </x-fx.panel>
    @endif
    @include('tax._footer')
</x-app-layout>
