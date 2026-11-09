<x-app-layout title="Access review">
<x-fx.page-head title="Access review">
    <x-slot name="sub">Who has access to {{ $activeLedger?->label() }}, with what role, and when they were last active. Review it, then confirm below so there is a dated record that someone looked.</x-slot>
    <x-slot name="actions">@if ($canExport)<x-fx.button :href="route('controls.access-review.export')" icon="download">Download as CSV</x-fx.button>@endif</x-slot>
</x-fx.page-head>

@include('controls._nav')

<div @class(['fx-verdict', 'fx-verdict--bad' => ! $latest])>
    <x-fx.icon :name="$latest ? 'circle-check' : 'circle-alert'" :class="$latest ? 'fx-tick-draw' : ''" />
    <div>
        @if ($latest)
        <strong>Last confirmed on {{ $latest->reviewed_at->toDateString() }}</strong>
        by {{ $latest->reviewer?->name ?? 'a former member' }} ({{ $latest->member_count }} {{ Str::plural('person', $latest->member_count) }}).
        @else
        <strong>Never confirmed</strong>
        Nobody has signed off on this list yet. Read it below, then confirm.
        @endif
    </div>
</div>

<x-fx.panel flush title="Everyone with access" :meta="count($rows).' '.Str::plural('person', count($rows))">
    <x-fx.table caption="Everyone with access">
        <thead>
            <tr>
                <th scope="col">Person</th>
                <th scope="col">Role in force</th>
                <th scope="col">Can do</th>
                <th scope="col">Last activity</th>
                <th scope="col">Role history</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <th scope="row" data-label="Person" class="is-lead">
                    <span class="fx-person"><x-fx.avatar :name="$row['name']" /><span><span class="fx-cell-main">{{ $row['name'] }}</span><span class="fx-cell-sub">{{ $row['email'] }}</span></span></span>
                </th>
                <td data-label="Role in force"><span class="fx-cellbody">
                    <x-fx.pill kind="plain">{{ $row['role']->label() }}</x-fx.pill>
                    <div class="fx-cell-sub">From InfoDot: {{ ucfirst($row['infodot_role']) }}</div>
                    <div class="fx-cell-sub">Local role: {{ $row['local_role']?->label() ?? 'None' }}@if ($row['granted_by']), set by {{ $row['granted_by'] }} on {{ $row['granted_at']?->toDateString() }}@endif</div>
                </span></td>
                <td data-label="Can do">{{ $row['capability_count'] }} of {{ count(\App\Enums\Capability::cases()) }} capabilities</td>
                <td data-label="Last activity"><span class="fx-cellbody">
                    <div>Session: {{ $row['last_session_activity']?->toDateTimeString() ?? 'No live session' }}</div>
                    <div class="fx-cell-sub">Last action here: {{ $row['last_action_here']?->toDateTimeString() ?? 'Nothing recorded' }}</div>
                </span></td>
                <td data-label="Role history">
                    @if ($row['history']->isEmpty())
                        No changes
                    @else
                        <details class="fx-disclose">
                            <summary>{{ $row['history']->count() }} {{ Str::plural('change', $row['history']->count()) }}</summary>
                            <ul>
                                @foreach ($row['history'] as $entry)
                                    <li>{{ $entry->created_at->toDateTimeString() }}: {{ $entry->action }} by {{ $entry->actorUser?->name ?? 'the system' }} ({{ $entry->changes['role']['before'] ?? 'none' }} to {{ $entry->changes['role']['after'] ?? 'none' }})</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    <x-slot name="foot">The app does not record sign-ins. "Session" is the latest activity of a session that is still stored (sessions expire, so it is a lower bound). "Last action here" comes from this ledger's audit log.</x-slot>
</x-fx.panel>

<div class="fx-grid fx-grid--even r-stack">
    @if ($canConfirm)
    <x-fx.panel title="Confirm this review" meta="Adds a dated record that you looked.">
        <form method="POST" action="{{ route('controls.access-review.store') }}" class="fx-form">
            @csrf
            <x-fx.textarea name="notes" id="review-notes" label="Notes" optional rows="3" maxlength="2000" />
            <div class="fx-formactions"><x-fx.button variant="primary" icon="user-round-check">I have reviewed this list</x-fx.button></div>
        </form>
    </x-fx.panel>
    @endif

    <x-fx.panel title="Earlier reviews">
        @if ($past->isEmpty())
        <x-fx.empty>None yet.</x-fx.empty>
        @else
        <ul class="fx-list">
            @foreach ($past as $review)
            <li><span class="fx-cell-main"><span class="fx-figure">{{ $review->reviewed_at->toDateString() }}</span> by {{ $review->reviewer?->name ?? 'a former member' }}</span>, {{ $review->member_count }} {{ Str::plural('person', $review->member_count) }}@if ($review->notes)<div class="fx-cell-sub">{{ $review->notes }}</div>@endif</li>
            @endforeach
        </ul>
        @endif
    </x-fx.panel>
</div>
</x-app-layout>
