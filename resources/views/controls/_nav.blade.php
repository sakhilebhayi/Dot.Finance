{{-- Controls navigation: plain links, restyled later by the UI work. --}}
<nav aria-label="Controls" class="controls-nav">
    <ul>
        <li><a href="{{ route('controls.index') }}" @if (request()->routeIs('controls.index')) aria-current="page" @endif>Overview</a></li>
        <li><a href="{{ route('controls.approvals.index') }}" @if (request()->routeIs('controls.approvals.*')) aria-current="page" @endif>Approvals</a></li>
        <li><a href="{{ route('controls.members.index') }}" @if (request()->routeIs('controls.members.*')) aria-current="page" @endif>Members and roles</a></li>
        <li><a href="{{ route('controls.policies.index') }}" @if (request()->routeIs('controls.policies.*')) aria-current="page" @endif>Approval policies</a></li>
        <li><a href="{{ route('controls.limits.index') }}" @if (request()->routeIs('controls.limits.*')) aria-current="page" @endif>Spend limits</a></li>
        <li><a href="{{ route('controls.access-review.index') }}" @if (request()->routeIs('controls.access-review.*')) aria-current="page" @endif>Access review</a></li>
    </ul>
</nav>
@if (session('status'))
    <p role="status" class="controls-status">{{ session('status') }}</p>
@endif
@if ($errors->any())
    <div role="alert" class="controls-errors">
        @foreach ($errors->all() as $message)
            <p>{{ $message }}</p>
        @endforeach
    </div>
@endif
