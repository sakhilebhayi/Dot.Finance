<x-app-layout>
<div class="page-pad controls-page">
    <h1>Access review</h1>
    <p>Who has access to {{ $activeLedger?->label() }}, with what role, and when they were last active. Review it, then confirm below so there is a dated record that someone looked.</p>
    @include('controls._nav')

    <p>
        @if ($latest)
            Last confirmed on {{ $latest->reviewed_at->toDateString() }} by {{ $latest->reviewer?->name ?? 'a former member' }} ({{ $latest->member_count }} {{ Str::plural('person', $latest->member_count) }}).
        @else
            Never confirmed.
        @endif
        @if ($canExport) <a href="{{ route('controls.access-review.export') }}">Download as CSV</a> @endif
    </p>

    <table class="controls-table">
        <caption>Everyone with access</caption>
        <thead>
            <tr>
                <th scope="col">Person</th>
                <th scope="col">From InfoDot</th>
                <th scope="col">Local role</th>
                <th scope="col">Role in force</th>
                <th scope="col">Can do</th>
                <th scope="col">Last session activity</th>
                <th scope="col">Last action here</th>
                <th scope="col">Role history</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <th scope="row">{{ $row['name'] }}<br><small>{{ $row['email'] }}</small></th>
                <td>{{ ucfirst($row['infodot_role']) }}</td>
                <td>{{ $row['local_role']?->label() ?? 'None' }}@if ($row['granted_by']) <small>set by {{ $row['granted_by'] }} on {{ $row['granted_at']?->toDateString() }}</small> @endif</td>
                <td>{{ $row['role']->label() }}</td>
                <td>{{ $row['capability_count'] }} of {{ count(\App\Enums\Capability::cases()) }} capabilities</td>
                <td>{{ $row['last_session_activity']?->toDateTimeString() ?? 'No live session' }}</td>
                <td>{{ $row['last_action_here']?->toDateTimeString() ?? 'Nothing recorded' }}</td>
                <td>
                    @if ($row['history']->isEmpty())
                        No changes
                    @else
                        <details>
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
    </table>
    <p><small>The app does not record sign-ins. "Last session activity" is the latest activity of a session that is still stored (sessions expire, so it is a lower bound). "Last action here" comes from this ledger's audit log.</small></p>

    @if ($canConfirm)
        <h2>Confirm this review</h2>
        <form method="POST" action="{{ route('controls.access-review.store') }}">
            @csrf
            <label for="review-notes">Notes (optional)</label>
            <textarea id="review-notes" name="notes" rows="3" maxlength="2000" class="dot-input"></textarea>
            <button type="submit" class="dot-btn dot-btn-primary">I have reviewed this list</button>
        </form>
    @endif

    <h2>Earlier reviews</h2>
    <ul>
        @forelse ($past as $review)
            <li>{{ $review->reviewed_at->toDateString() }} by {{ $review->reviewer?->name ?? 'a former member' }}, {{ $review->member_count }} {{ Str::plural('person', $review->member_count) }}@if ($review->notes): {{ $review->notes }}@endif</li>
        @empty
            <li>None yet.</li>
        @endforelse
    </ul>
</div>
</x-app-layout>
