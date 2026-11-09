<x-app-layout title="Notification settings">

<x-fx.page-head title="What you get" sub="Your choices for {{ $ledger->label() }}. They are yours: nobody else's messages change when you change these.">
    <x-slot name="actions"><x-fx.button :href="route('intel.notifications.index')">Back to notifications</x-fx.button></x-slot>
</x-fx.page-head>

<x-fx.flash />

<form method="POST" action="{{ route('intel.notifications.preferences.update') }}">
    @csrf
    @method('PUT')
    <x-fx.panel flush>
        <x-fx.table caption="Notification choices by kind" class="intel-prefs">
            <thead>
                <tr>
                    <th scope="col">Kind</th>
                    <th scope="col">In the app</th>
                    <th scope="col">By e-mail</th>
                    @if ($notifyConnected)<th scope="col">Via Dot.Notify</th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($kinds as $kind => $row)
                <tr>
                    <th scope="row" data-label="Kind">{{ $row['label'] }}</th>
                    <td data-label="In the app"><x-fx.check :name="'kinds['.$kind.'][in_app]'" :checked="$row['in_app']" :id="'p-'.$kind.'-app'"><span class="sr-only">{{ $row['label'] }} in the app</span></x-fx.check></td>
                    <td data-label="By e-mail"><x-fx.check :name="'kinds['.$kind.'][email]'" :checked="$row['email']" :id="'p-'.$kind.'-mail'"><span class="sr-only">{{ $row['label'] }} by e-mail</span></x-fx.check></td>
                    @if ($notifyConnected)
                    <td data-label="Via Dot.Notify"><x-fx.check :name="'kinds['.$kind.'][notify]'" :checked="$row['notify']" :id="'p-'.$kind.'-notify'"><span class="sr-only">{{ $row['label'] }} via Dot.Notify</span></x-fx.check></td>
                    @endif
                </tr>
                @endforeach
            </tbody>
        </x-fx.table>
        <x-slot name="foot"><x-fx.button variant="primary">Save my choices</x-fx.button></x-slot>
    </x-fx.panel>
</form>

<p class="fx-hint">E-mails go from this app to your own address only. Customers and suppliers never receive anything from here.@unless ($notifyConnected) Dot.Notify forwarding is not connected on this server.@endunless</p>

</x-app-layout>
