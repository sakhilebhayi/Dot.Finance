<x-app-layout title="Approvals">
<x-fx.page-head title="Approvals" sub="Requests that need a second person. The person who asked can never approve their own request, unless they are the only member of the ledger and the policy allows it." />

@include('controls._nav')

<x-fx.tabs label="Approval lists" :items="[
    ['label' => 'Waiting for me ('.$waiting->count().')', 'href' => route('controls.approvals.index', ['tab' => 'waiting']), 'current' => $tab === 'waiting'],
    ['label' => 'My requests', 'href' => route('controls.approvals.index', ['tab' => 'mine']), 'current' => $tab === 'mine'],
    ['label' => 'History', 'href' => route('controls.approvals.index', ['tab' => 'history']), 'current' => $tab === 'history'],
]" />

@if ($tab === 'waiting')
    @forelse ($waiting as $row)
    @php $r = $row['request']; @endphp
    <x-fx.panel>
        <article class="fx-request" aria-labelledby="request-{{ $r->id }}-title">
            <header class="fx-request__head">
                <div>
                    <h2 class="fx-request__title" id="request-{{ $r->id }}-title">{{ $r->subject_kind->label() }} {{ $r->label }}</h2>
                    <p class="fx-request__sub">
                        Asked by {{ $r->requester?->name ?? 'a former member' }} {{ $r->requested_at?->diffForHumans() }}.
                        Step {{ min($r->steps_done + 1, $r->steps_required) }} of {{ $r->steps_required }}.
                    </p>
                </div>
                <x-fx.money :cents="$r->amount_cents" :currency="$r->currency" lead />
            </header>
            @if ($row['blocked'])
            <div class="fx-notice" role="note"><x-fx.icon name="info" /><p>{{ $row['blocked'] }}</p></div>
            @endif
            <div class="fx-request__decide">
                <form method="POST" action="{{ route('controls.approvals.approve', $r) }}" class="fx-decide">
                    @csrf
                    <x-fx.input name="reason" id="approve-{{ $r->id }}" label="Note" optional maxlength="1000" :bind="false" />
                    <x-fx.button variant="primary" icon="check">Approve<span class="sr-only"> {{ $r->label }}</span></x-fx.button>
                </form>
                <form method="POST" action="{{ route('controls.approvals.reject', $r) }}" class="fx-decide" data-fx-confirm="Reject {{ $r->subject_kind->label() }} {{ $r->label }}? The person who asked is told your reason." data-fx-confirm-label="Reject">
                    @csrf
                    <x-fx.input name="reason" id="reject-{{ $r->id }}" label="Why you are rejecting it" required maxlength="1000" :bind="false" />
                    <x-fx.button variant="danger" icon="ban">Reject<span class="sr-only"> {{ $r->label }}</span></x-fx.button>
                </form>
            </div>
        </article>
    </x-fx.panel>
    @empty
    <x-fx.empty title="Nothing is waiting for you.">Nothing is waiting for you. When someone asks for a second pair of eyes on a bill, payment or invoice, it shows up here.</x-fx.empty>
    @endforelse
@elseif ($tab === 'mine')
    <x-fx.panel flush title="Requests you asked for">
        @if ($mine->isEmpty())
        <x-fx.empty>You have not asked for any approvals.</x-fx.empty>
        @else
        <x-fx.table caption="Requests you asked for">
            <thead><tr><th scope="col">What</th><th scope="col" class="is-num">Amount</th><th scope="col">Asked</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
            <tbody>
            @foreach ($mine as $r)
                <tr>
                    <th scope="row" data-label="What" class="is-lead"><span class="fx-cell-main">{{ $r->subject_kind->label() }} {{ $r->label }}</span></th>
                    <td data-label="Amount" class="is-num"><x-fx.money :cents="$r->amount_cents" :currency="$r->currency" /></td>
                    <td data-label="Asked"><span class="fx-figure">{{ $r->requested_at?->toDateString() }}</span></td>
                    <td data-label="Status"><span class="fx-cellbody">{{ ucfirst($r->status) }}@if ($r->isPending()) <span class="fx-cell-sub">(step {{ $r->steps_done }} of {{ $r->steps_required }} done)</span> @endif</span></td>
                    <td class="is-actions">
                        @if ($r->isPending())
                        <form method="POST" action="{{ route('controls.approvals.cancel', $r) }}" data-fx-confirm="Cancel this request? Nobody will be asked to approve it." data-fx-confirm-label="Cancel request">@csrf <x-fx.button size="sm">Cancel request<span class="sr-only"> {{ $r->label }}</span></x-fx.button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        @endif
    </x-fx.panel>
@else
    <x-fx.panel flush title="Decided requests" meta="Newest first">
        @if ($history->isEmpty())
        <x-fx.empty>Nothing has been decided yet.</x-fx.empty>
        @else
        <x-fx.table caption="Decided requests, newest first">
            <thead><tr><th scope="col">What</th><th scope="col" class="is-num">Amount</th><th scope="col">Asked by</th><th scope="col">Outcome</th><th scope="col">Decided by</th><th scope="col">When</th><th scope="col">Reason</th></tr></thead>
            <tbody>
            @foreach ($history as $r)
                <tr>
                    <th scope="row" data-label="What" class="is-lead"><span class="fx-cell-main">{{ $r->subject_kind->label() }} {{ $r->label }}</span></th>
                    <td data-label="Amount" class="is-num"><x-fx.money :cents="$r->amount_cents" :currency="$r->currency" /></td>
                    <td data-label="Asked by">{{ $r->requester?->name ?? 'A former member' }}</td>
                    <td data-label="Outcome"><x-fx.pill :kind="$r->status === 'approved' ? 'ticked' : 'neutral'" :icon="$r->status === 'approved' ? 'check' : 'ban'">{{ ucfirst($r->status) }}</x-fx.pill></td>
                    <td data-label="Decided by">{{ $r->decider?->name ?? 'Automatic' }}</td>
                    <td data-label="When"><span class="fx-figure">{{ $r->decided_at?->toDateTimeString() }}</span></td>
                    <td data-label="Reason">{{ $r->reason }}</td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        @endif
    </x-fx.panel>
@endif
</x-app-layout>
