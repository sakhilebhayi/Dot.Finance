@if ($approval)
<x-fx.panel title="Approval">
    <p>
        @if ($approval->isPending()) Waiting for approval from someone who may approve it; the person who asked cannot.
        @elseif ($approval->status === 'approved') Approved @if ($approval->decided_at) on {{ $approval->decided_at->format('j M Y') }}@endif.
        @elseif ($approval->status === 'rejected') Rejected: {{ $approval->reason }}
        @else Request {{ $approval->status }}.
        @endif
    </p>
    <x-slot name="foot">@if($approval->isPending())<a class="fx-link" href="{{ route('controls.approvals.index') }}">Open the approvals inbox</a>@endif</x-slot>
</x-fx.panel>
@endif
