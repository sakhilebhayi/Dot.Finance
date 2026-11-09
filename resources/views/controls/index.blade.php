<x-app-layout>
<div class="page-pad controls-page">
    <h1>Controls</h1>
    <p>Who can do what in {{ $activeLedger?->label() ?? 'this ledger' }}, what needs a second person, and the records that prove it. You are {{ $access->roleLabel() }} here.</p>
    @include('controls._nav')

    <ul class="controls-summary">
        <li><a href="{{ route('controls.approvals.index') }}">{{ $waiting }} {{ Str::plural('approval', $waiting) }} waiting for you</a></li>
        <li><a href="{{ route('controls.policies.index') }}">{{ $activePolicies }} approval {{ Str::plural('policy', $activePolicies) }} on{{ $activePolicies === 0 ? ' (nothing needs approval yet)' : '' }}</a></li>
        <li>
            <a href="{{ route('controls.access-review.index') }}">
                @if ($lastReview)
                    Access last reviewed {{ $lastReview->reviewed_at->diffForHumans() }} by {{ $lastReview->reviewer?->name ?? 'a former member' }}
                @else
                    Access has not been reviewed yet
                @endif
            </a>
        </li>
    </ul>
</div>
</x-app-layout>
