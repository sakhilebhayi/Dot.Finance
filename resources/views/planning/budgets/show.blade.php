<x-app-layout :title="$budget->name">
@php $cur = $budget->currency; @endphp
<x-fx.page-head :title="$budget->name" :sub="'Version '.$budget->version.($budget->isReforecast() ? ', a re-forecast as at '.$budget->as_of?->format('j M Y') : '').', '.\App\Services\Reporting\ReportFormat::date($budget->starts_on->toDateString()).' to '.\App\Services\Reporting\ReportFormat::date($budget->ends_on->toDateString())">
    <x-slot name="actions">
        <x-fx.button :href="route('reports.budget-vs-actual', ['budget' => $budget->id, 'from' => $budget->starts_on->toDateString(), 'to' => $budget->ends_on->toDateString()])" variant="ghost" icon="scale">Budget against actual</x-fx.button>
        @if ($canManage && $budget->isEditable())<x-fx.button :href="route('planning.budgets.edit', $budget)" variant="ghost" icon="pencil">Edit amounts</x-fx.button>@endif
    </x-slot>
</x-fx.page-head>
<x-fx.flash /><x-fx.errors />

<p><x-fx.pill :kind="$budget->status === 'approved' ? 'ticked' : ($budget->status === 'rejected' ? 'variance' : 'neutral')">{{ $budget->status }}</x-fx.pill>
    Revenue target <x-fx.money :cents="$totals['income']" :currency="$cur" />, spending limit <x-fx.money :cents="$totals['expense']" :currency="$cur" />.
    @if ($approval) Approval request: {{ $approval->status }}@if ($approval->status === 'pending') (waiting on the approvers; <a href="{{ route('controls.approvals.index') }}">see approvals</a>)@endif.@endif</p>

@if ($canManage)
<div class="fx-cluster">
    @if ($budget->isEditable())
    <form method="POST" action="{{ route('planning.budgets.submit', $budget) }}">@csrf
        <x-fx.button variant="primary">{{ $needsApproval ? 'Send for approval' : 'Approve this plan' }}</x-fx.button>
    </form>
    @if (! $needsApproval && ! $canApprove)<span class="fx-hint">No approval policy covers budgets, so someone who can approve budgets approves it.</span>@endif
    @endif
    <form method="POST" action="{{ route('planning.budgets.version', $budget) }}">@csrf<x-fx.button variant="ghost">Make a new version</x-fx.button></form>
</div>
@if ($budget->status === 'approved' && ! $budget->isReforecast())
<form method="POST" action="{{ route('planning.budgets.reforecast', $budget) }}" class="rp-toolbar" aria-label="Re-forecast">
    @csrf
    <x-fx.input name="as_of" label="Re-forecast as at" type="date" :value="now()->toDateString()" hint="Months that ended by then become actuals from the books." />
    <x-fx.select name="method" label="Later months">
        <option value="hold">Keep the plan</option>
        <option value="run_rate">Use the last 3 months' average</option>
    </x-fx.select>
    <div class="rp-toolbar__actions"><x-fx.button variant="ghost">Make a re-forecast</x-fx.button></div>
</form>
@endif
@endif

@if ($grid === [])
<x-fx.empty title="No amounts yet.">@if ($canManage && $budget->isEditable())<x-slot name="action"><x-fx.button :href="route('planning.budgets.edit', $budget)" variant="primary">Add amounts</x-fx.button></x-slot>@endif Add what you intend to earn and spend, month by month.</x-fx.empty>
@else
<x-fx.panel flush>
<x-fx.table caption="The plan by account and month" scroll class="rp-table">
    <thead><tr><th scope="col">Account</th>@foreach ($months as $m)<th scope="col" class="is-num">{{ \Carbon\CarbonImmutable::parse($m)->format('M y') }}</th>@endforeach<th scope="col" class="is-num">Total</th></tr></thead>
    <tbody>
    @foreach ($grid as $row)
    <tr>
        <th scope="row" data-label="" class="is-lead">{{ $row['account']->code }} {{ $row['account']->name }}<span class="fx-cell-sub">{{ $row['account']->class === 'income' ? 'Revenue target' : 'Spending limit' }}@if ($row['dimension']), {{ $dimensions[$row['dimension']] ?? $row['dimension'] }}@endif</span></th>
        @foreach ($months as $m)<td data-label="{{ \Carbon\CarbonImmutable::parse($m)->format('M y') }}" class="is-num @if ($budget->isReforecast() && $budget->as_of && \Carbon\CarbonImmutable::parse($m)->endOfMonth()->gt($budget->as_of)) rp-projected @endif">@if (isset($row['months'][$m]))<x-fx.money :cents="$row['months'][$m]" />@else&ndash;@endif</td>@endforeach
        <td data-label="Total" class="is-num"><x-fx.money :cents="array_sum($row['months'])" /></td>
    </tr>
    @endforeach
    </tbody>
</x-fx.table>
</x-fx.panel>
@if ($budget->isReforecast())<p class="fx-hint"><span class="rp-key"><span class="rp-key__swatch rp-key__swatch--projected"></span> Hatched months are projected. Plain months are actuals copied from the books.</span></p>@endif
@endif
</x-app-layout>
