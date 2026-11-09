<x-app-layout title="Tax">
    <x-fx.page-head title="Tax" sub="What applies to this business, what is due, and what is missing. Dot.Finance prepares; a person files with SARS.">
        <x-slot name="actions">
            <x-fx.button :href="route('tax.profile.edit')" variant="secondary">{{ $profile ? 'Tax profile' : 'Set up the tax profile' }}</x-fx.button>
        </x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash />

    @if (! $profile)
        <x-fx.empty title="No tax profile yet.">
            Tell us the legal entity, the registrations and the financial year end. Until then nothing is assumed about which taxes apply.
            <x-slot name="action"><x-fx.button :href="route('tax.profile.edit')" variant="primary">Set up the tax profile</x-fx.button></x-slot>
        </x-fx.empty>
    @else
        <div class="fx-grid fx-grid--lead">
            <x-fx.panel title="Coming up" meta="Open and prepared, soonest first" flush>
                @if ($deadlines->isEmpty())
                    <x-fx.empty title="No deadlines yet.">The calendar is built from the profile and the rules.
                        <x-slot name="action">@if ($canManage)<form method="POST" action="{{ route('tax.calendar.generate') }}">@csrf<x-fx.button variant="primary">Build the calendar</x-fx.button></form>@endif</x-slot>
                    </x-fx.empty>
                @else
                <x-fx.table caption="Upcoming tax deadlines">
                    <thead><tr><th scope="col">What</th><th scope="col">Period</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                    @foreach ($deadlines as $d)
                        @php $s = $d->displayStatus($today); @endphp
                        <tr>
                            <td data-label="What" class="is-lead"><a class="fx-link" href="{{ route('tax.calendar.show', $d) }}">{{ $d->label() }}</a></td>
                            <td data-label="Period">{{ $d->period_label }}</td>
                            <td data-label="Due">{{ $d->due_date->format('j M Y') }}</td>
                            <td data-label="Status"><x-fx.pill :kind="$s === 'overdue' ? 'variance' : 'plain'">{{ ucfirst($s) }}</x-fx.pill></td>
                        </tr>
                    @endforeach
                    </tbody>
                </x-fx.table>
                @endif
            </x-fx.panel>

            <x-fx.panel title="What applies" meta="With the reasons">
                <ul class="fx-list">
                    @foreach ($obligations as $o)
                    <li>
                        <strong>{{ $o->label }}</strong>
                        <x-fx.pill :kind="in_array($o->status, ['review', 'not_configured'], true) ? 'variance' : 'plain'">{{ str_replace('_', ' ', ucfirst($o->status)) }}</x-fx.pill>
                        <p class="fx-hint">{{ $o->summary }}</p>
                    </li>
                    @endforeach
                </ul>
                <x-slot name="foot"><a class="fx-link" href="{{ route('tax.obligations') }}">Read the working behind each one</a></x-slot>
            </x-fx.panel>
        </div>

        @if ($correspondence->isNotEmpty())
        <x-fx.panel title="Waiting for a reply to SARS" flush>
            <x-fx.table caption="Open SARS correspondence">
                <thead><tr><th scope="col">Subject</th><th scope="col">Received</th><th scope="col">Reply by</th></tr></thead>
                <tbody>@foreach ($correspondence as $c)<tr><td data-label="Subject" class="is-lead">{{ $c->subject }}</td><td data-label="Received">{{ $c->occurred_on->format('j M Y') }}</td><td data-label="Reply by">{{ $c->response_due_on?->format('j M Y') ?? 'not stated' }}</td></tr>@endforeach</tbody>
            </x-fx.table>
        </x-fx.panel>
        @endif
        @if ($undelivered > 0)<p class="fx-hint">{{ $undelivered }} reminder record(s) are waiting to be delivered.</p>@endif
    @endif
    @include('tax._footer')
</x-app-layout>
