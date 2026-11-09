<x-app-layout title="Integrations">

<x-fx.page-head title="Integrations" sub="What Dot.Finance is connected to, stated as it is. Connected means a real signed channel exists on both sides. Contract only means this side is built and waiting for the other. Not possible means the other platform offers nothing to connect to.">
    <x-slot name="actions"><x-fx.button :href="route('intel.integrations.emall.index')" icon="shopping-bag">Dot.Emall sales</x-fx.button></x-slot>
</x-fx.page-head>

<x-fx.panel flush>
    <x-fx.table caption="Integrations and their status" class="intel-wrap">
        <thead><tr><th scope="col">Platform</th><th scope="col">Status</th><th scope="col">On this server</th><th scope="col">What it does, and what is needed</th></tr></thead>
        <tbody>
            @foreach ($integrations as $row)
            <tr>
                <th scope="row" class="is-lead" data-label="Platform">{{ $row['name'] }}</th>
                <td data-label="Status">
                    @if ($row['status'] === 'connected' && $row['configured'])<x-fx.pill kind="ticked">Connected</x-fx.pill>
                    @elseif ($row['status'] === 'connected')<x-fx.pill kind="plain" icon="circle-dashed">Built, switched off</x-fx.pill>
                    @elseif ($row['status'] === 'contract_only')<x-fx.pill kind="plain" icon="circle-dashed">Contract only</x-fx.pill>
                    @else<x-fx.pill kind="plain" icon="ban">Not possible</x-fx.pill>@endif
                </td>
                <td data-label="On this server">{{ $row['configured'] === null ? 'Not applicable' : ($row['configured'] ? 'Configured' : 'Not configured (inert)') }}</td>
                <td data-label="What it does, and what is needed">
                    <p>{{ $row['summary'] }}</p>
                    <p class="fx-hint">{{ $row['owner_step'] }}</p>
                </td>
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>

</x-app-layout>
