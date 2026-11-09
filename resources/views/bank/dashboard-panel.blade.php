@if (count($rows) > 0)
<x-fx.panel title="Bank accounts against their statements" flush>
    <x-fx.table caption="Reconciliation state per account">
        <thead><tr><th scope="col">Account</th><th scope="col">Reconciled to</th><th scope="col" class="is-num">Lines needing a decision</th></tr></thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td data-label="Account" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('bank.reconciliation.index') }}">{{ $row['name'] }}</a></td>
                <td data-label="Reconciled to">@if ($row['reconciled_to'])<span class="fx-date">{{ $row['reconciled_to'] }}</span>@else<span class="fx-cell-sub">Never</span>@endif</td>
                <td data-label="Lines needing a decision" class="is-num">@if ($row['open'] > 0)<x-fx.pill kind="variance">{{ $row['open'] }}</x-fx.pill>@else<x-fx.pill kind="ticked">0</x-fx.pill>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>
@endif
