<x-app-layout>
<div class="page-pad controls-page">
    <h1>Approvals</h1>
    <p>Requests that need a second person. The person who asked can never approve their own request, unless they are the only member of the ledger and the policy allows it.</p>
    @include('controls._nav')

    <nav aria-label="Approval lists" class="controls-tabs">
        <a href="{{ route('controls.approvals.index', ['tab' => 'waiting']) }}" @if ($tab === 'waiting') aria-current="page" @endif>Waiting for me ({{ $waiting->count() }})</a>
        <a href="{{ route('controls.approvals.index', ['tab' => 'mine']) }}" @if ($tab === 'mine') aria-current="page" @endif>My requests</a>
        <a href="{{ route('controls.approvals.index', ['tab' => 'history']) }}" @if ($tab === 'history') aria-current="page" @endif>History</a>
    </nav>

    @if ($tab === 'waiting')
        @forelse ($waiting as $row)
            @php $r = $row['request']; @endphp
            <article class="controls-request">
                <h2>{{ $r->subject_kind->label() }} {{ $r->label }}</h2>
                <p>
                    {{ \App\Support\Money::format($r->amount_cents, $r->currency) }},
                    asked by {{ $r->requester?->name ?? 'a former member' }} {{ $r->requested_at?->diffForHumans() }}.
                    Step {{ min($r->steps_done + 1, $r->steps_required) }} of {{ $r->steps_required }}.
                </p>
                @if ($row['blocked']) <p role="note">{{ $row['blocked'] }}</p> @endif
                <form method="POST" action="{{ route('controls.approvals.approve', $r) }}">
                    @csrf
                    <label for="approve-{{ $r->id }}">Note (optional)</label>
                    <input id="approve-{{ $r->id }}" name="reason" maxlength="1000" class="dot-input">
                    <button type="submit" class="dot-btn dot-btn-primary">Approve</button>
                </form>
                <form method="POST" action="{{ route('controls.approvals.reject', $r) }}">
                    @csrf
                    <label for="reject-{{ $r->id }}">Why you are rejecting it</label>
                    <input id="reject-{{ $r->id }}" name="reason" maxlength="1000" class="dot-input" required>
                    <button type="submit" class="dot-btn">Reject</button>
                </form>
            </article>
        @empty
            <p>Nothing is waiting for you.</p>
        @endforelse
    @elseif ($tab === 'mine')
        <table class="controls-table">
            <caption>Requests you asked for</caption>
            <thead><tr><th scope="col">What</th><th scope="col">Amount</th><th scope="col">Asked</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
            <tbody>
            @forelse ($mine as $r)
                <tr>
                    <th scope="row">{{ $r->subject_kind->label() }} {{ $r->label }}</th>
                    <td>{{ \App\Support\Money::format($r->amount_cents, $r->currency) }}</td>
                    <td>{{ $r->requested_at?->toDateString() }}</td>
                    <td>{{ ucfirst($r->status) }}@if ($r->isPending()) (step {{ $r->steps_done }} of {{ $r->steps_required }} done) @endif</td>
                    <td>
                        @if ($r->isPending())
                            <form method="POST" action="{{ route('controls.approvals.cancel', $r) }}">@csrf <button type="submit" class="dot-btn">Cancel request</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">You have not asked for any approvals.</td></tr>
            @endforelse
            </tbody>
        </table>
    @else
        <table class="controls-table">
            <caption>Decided requests, newest first</caption>
            <thead><tr><th scope="col">What</th><th scope="col">Amount</th><th scope="col">Asked by</th><th scope="col">Outcome</th><th scope="col">Decided by</th><th scope="col">When</th><th scope="col">Reason</th></tr></thead>
            <tbody>
            @forelse ($history as $r)
                <tr>
                    <th scope="row">{{ $r->subject_kind->label() }} {{ $r->label }}</th>
                    <td>{{ \App\Support\Money::format($r->amount_cents, $r->currency) }}</td>
                    <td>{{ $r->requester?->name ?? 'A former member' }}</td>
                    <td>{{ ucfirst($r->status) }}</td>
                    <td>{{ $r->decider?->name ?? 'Automatic' }}</td>
                    <td>{{ $r->decided_at?->toDateTimeString() }}</td>
                    <td>{{ $r->reason }}</td>
                </tr>
            @empty
                <tr><td colspan="7">Nothing has been decided yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif
</div>
</x-app-layout>
