<x-app-layout title="Controls">
<x-fx.page-head title="Controls">
    <x-slot name="sub">Who can do what in {{ $activeLedger?->label() ?? 'this ledger' }}, what needs a second person, and the records that prove it. You are {{ $access->roleLabel() }} here.</x-slot>
</x-fx.page-head>

@include('controls._nav')

<x-fx.panel title="Where things stand">
    <ul class="fx-lines">
        <li class="fx-line">
            <x-fx.icon :name="$waiting > 0 ? 'circle-alert' : 'check'" :class="$waiting > 0 ? 'fx-line__icon is-variance' : 'fx-line__icon'" />
            <div class="fx-line__main">
                <a class="fx-line__title fx-link" href="{{ route('controls.approvals.index') }}">{{ $waiting }} {{ Str::plural('approval', $waiting) }} waiting for you</a>
                <p class="fx-line__sub">{{ $waiting > 0 ? 'Someone is waiting for your yes or no.' : 'Nothing is held up on you.' }}</p>
            </div>
        </li>
        <li class="fx-line">
            <x-fx.icon name="shield-check" class="fx-line__icon" />
            <div class="fx-line__main">
                <a class="fx-line__title fx-link" href="{{ route('controls.policies.index') }}">{{ $activePolicies }} approval {{ Str::plural('policy', $activePolicies) }} on{{ $activePolicies === 0 ? ' (nothing needs approval yet)' : '' }}</a>
                <p class="fx-line__sub">{{ $activePolicies === 0 ? 'Turn one on and big payments, bills or invoices will need a second person.' : 'These decide which amounts need a second person.' }}</p>
            </div>
        </li>
        <li class="fx-line">
            <x-fx.icon name="user-round-check" class="fx-line__icon" />
            <div class="fx-line__main">
                <a class="fx-line__title fx-link" href="{{ route('controls.access-review.index') }}">
                    @if ($lastReview)
                    Access last reviewed {{ $lastReview->reviewed_at->diffForHumans() }} by {{ $lastReview->reviewer?->name ?? 'a former member' }}
                    @else
                    Access has not been reviewed yet
                    @endif
                </a>
                <p class="fx-line__sub">A dated record that someone looked at who can get in.</p>
            </div>
        </li>
    </ul>
</x-fx.panel>

<x-fx.panel title="What each page is for">
    <ul class="fx-lines">
        <li class="fx-line"><div class="fx-line__main"><a class="fx-line__title fx-link" href="{{ route('controls.members.index') }}">Members and roles</a><p class="fx-line__sub">Give each person the part they play: owner, finance manager, accountant, bookkeeper, approver or viewer.</p></div></li>
        <li class="fx-line"><div class="fx-line__main"><a class="fx-line__title fx-link" href="{{ route('controls.policies.index') }}">Approval policies</a><p class="fx-line__sub">Choose which amounts need a second person, and who may say yes.</p></div></li>
        <li class="fx-line"><div class="fx-line__main"><a class="fx-line__title fx-link" href="{{ route('controls.limits.index') }}">Spend limits</a><p class="fx-line__sub">Cap how much any one approver can approve.</p></div></li>
        <li class="fx-line"><div class="fx-line__main"><a class="fx-line__title fx-link" href="{{ route('controls.access-review.index') }}">Access review</a><p class="fx-line__sub">See everyone with access and when they were last active, then sign it off.</p></div></li>
    </ul>
</x-fx.panel>
</x-app-layout>
