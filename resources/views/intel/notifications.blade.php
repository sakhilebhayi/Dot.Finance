<x-app-layout title="Notifications">

<x-fx.page-head title="Notifications" :sub="$unread === 0 ? 'Nothing unread.' : $unread.' unread.'">
    <x-slot name="actions">
        <x-fx.button :href="route('intel.notifications.preferences')" icon="settings">Choose what you get</x-fx.button>
        @if ($unread > 0)
        <form method="POST" action="{{ route('intel.notifications.read-all') }}">@csrf<x-fx.button>Mark all read</x-fx.button></form>
        @endif
    </x-slot>
</x-fx.page-head>

<x-fx.flash />

<x-fx.panel title="For you in {{ $activeLedger?->label() ?? 'this ledger' }}">
    @forelse ($notifications as $note)
    @php $serious = in_array($note->severity, ['warning', 'critical'], true); @endphp
    <article @class(['intel-item', 'is-variance' => $serious, 'is-unread' => $note->read_at === null])>
        <x-fx.icon :name="$serious ? 'triangle-alert' : 'bell'" />
        <div class="intel-item__body">
            <h3>{{ $note->title }}</h3>
            <p>{{ $note->body }}</p>
            <div class="intel-actions">
                <span class="fx-hint">{{ \App\Models\Intelligence\FinanceNotification::KINDS[$note->kind] ?? $note->kind }}, {{ $note->created_at->format('j M, H:i') }}</span>
                @if ($note->href)
                <form method="POST" action="{{ route('intel.notifications.read', $note) }}">@csrf<input type="hidden" name="open" value="1"><x-fx.button size="sm" icon="arrow-right">Open</x-fx.button></form>
                @endif
                @if ($note->read_at === null)
                <form method="POST" action="{{ route('intel.notifications.read', $note) }}">@csrf<x-fx.button size="sm" variant="ghost">Mark read</x-fx.button></form>
                @endif
            </div>
        </div>
    </article>
    @empty
    <x-fx.empty title="No notifications yet.">Approvals waiting for you, tax deadlines, overdue summaries and new warnings arrive here.</x-fx.empty>
    @endforelse
    <x-slot name="foot">{{ $notifications->links() }}</x-slot>
</x-fx.panel>

</x-app-layout>
