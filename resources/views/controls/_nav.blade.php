{{-- The Controls pages as tabs; the current one carries aria-current (state is the URL). --}}
<x-fx.tabs label="Controls" :items="[
    ['label' => 'Overview', 'href' => route('controls.index'), 'current' => request()->routeIs('controls.index')],
    ['label' => 'Approvals', 'href' => route('controls.approvals.index'), 'current' => request()->routeIs('controls.approvals.*')],
    ['label' => 'Members and roles', 'href' => route('controls.members.index'), 'current' => request()->routeIs('controls.members.*')],
    ['label' => 'Approval policies', 'href' => route('controls.policies.index'), 'current' => request()->routeIs('controls.policies.*')],
    ['label' => 'Spend limits', 'href' => route('controls.limits.index'), 'current' => request()->routeIs('controls.limits.*')],
    ['label' => 'Access review', 'href' => route('controls.access-review.index'), 'current' => request()->routeIs('controls.access-review.*')],
]" />
<x-fx.flash />
<x-fx.errors />
