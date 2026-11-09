<x-app-layout title="Chart of accounts">
@php
    $classMeta = [
        'asset' => ['Assets', 'What the business owns or is owed: bank, money customers owe you, equipment.'],
        'liability' => ['Liabilities', 'What the business owes: suppliers, VAT, loans, salaries still to pay.'],
        'equity' => ['Equity', 'What belongs to the owners: money put in and profit kept.'],
        'income' => ['Income', 'Money earned from selling things.'],
        'expense' => ['Expenses', 'Money spent running the business.'],
    ];
    // Flatten one class into rows with a depth, children under their parent, in code order.
    $tree = function ($list) {
        $ids = $list->pluck('id')->all();
        $children = $list->groupBy(fn ($a) => in_array($a->parent_id, $ids, true) ? $a->parent_id : 0);
        $out = [];
        $walk = function ($parent, $depth) use (&$walk, &$out, $children) {
            foreach ($children->get($parent, collect()) as $account) {
                $out[] = [$account, min($depth, 3)];
                $walk($account->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    };
@endphp

<x-fx.page-head title="Chart of accounts" sub="The labels every rand is filed under, grouped by what they are. Each balance opens the lines behind it.">
    <x-slot name="actions">
        @if ($canManage)<x-fx.button variant="primary" :href="route('accounting.chart.create')" icon="plus">Add an account</x-fx.button>@endif
    </x-slot>
</x-fx.page-head>

@include('accounting._nav')
@include('accounting._messages')

@if ($suspense)
<section class="fx-notice fx-notice--warn" role="status" aria-labelledby="suspense-title">
    <x-fx.icon name="circle-alert" />
    <div>
        <h2 id="suspense-title" class="fx-notice__title">{{ $suspense->label() }} holds <x-fx.money :cents="abs($suspenseBalance)" :variance="true" /></h2>
        <p>Suspense is a parking place for money nobody has sorted yet. It should be nil, and while it is not, the balance sheet lists it under assets as if it were owned. <a class="fx-link" href="{{ route('accounting.general-ledger', ['account' => $suspense->id]) }}">See what is on it</a> and move each entry to the account it belongs to.</p>
    </div>
</section>
@endif

<p class="fx-hint fx-hint--wide">Each balance is shown on its normal side: assets and expenses count debits as positive, liabilities, equity and income count credits as positive. A figure in brackets is on the unusual side.</p>

@foreach ($classes as $class)
@continue(! isset($accounts[$class]))
@php
    $rows = $tree($accounts[$class]);
    $debitNatured = in_array($class, ['asset', 'expense'], true);
    $natural = fn ($id) => $debitNatured ? $balances[$id] : -$balances[$id];
    $classTotal = $accounts[$class]->sum(fn ($a) => $a->is_postable ? $natural($a->id) : 0);
    [$classLabel, $classHelp] = $classMeta[$class] ?? [ucfirst($class), ''];
@endphp
<x-fx.panel flush :title="$classLabel" :meta="$classHelp" :id="'class-'.$class">
    <x-fx.table caption="{{ ucfirst($class) }} accounts" class="fx-table--statement fx-table--tree fx-table--stack" keep>
        <thead>
            <tr>
                <th scope="col">Account</th>
                <th scope="col">Status</th>
                <th scope="col" class="is-num">Balance</th>
                @if($canManage)<th scope="col"><span class="sr-only">Actions</span></th>@endif
            </tr>
        </thead>
        <tbody>
        @foreach ($rows as [$account, $depth])
            @php $balance = $natural($account->id); @endphp
            <tr @class(['is-header' => ! $account->is_postable, 'is-inactive' => ! $account->is_active])>
                <th scope="row" class="fx-acct fx-depth-{{ $depth }}">
                    <span class="fx-acct__line"><span class="fx-figure fx-acct__num">{{ $account->code }}</span> <span class="fx-acct__name">{{ $account->name }}</span>@if($account->isSystem()) <x-fx.pill kind="plain" icon="lock">System</x-fx.pill>@endif @if($suspense && $account->id === $suspense->id) <x-fx.pill kind="variance">Should be nil</x-fx.pill>@endif</span>
                </th>
                <td @class(['fx-col-status', 'is-default' => $account->is_active && $account->is_postable])>
                    {{ $account->is_active ? 'Active' : 'Inactive' }}@unless($account->is_postable), report line @endunless
                </td>
                <td data-label="Balance" class="is-num">
                    @if ($account->is_postable && $balance !== 0)
                    <x-fx.money :cents="$balance" :trace="route('accounting.general-ledger', ['account' => $account->id])" trace-label="Show the postings behind {{ $account->name }}" />
                    @elseif ($account->is_postable)
                    <x-fx.money :cents="0" quiet />
                    @endif
                </td>
                @if ($canManage)
                <td class="is-actions">
                    <a href="{{ route('accounting.chart.edit', $account) }}" class="fx-link">Edit<span class="sr-only"> {{ $account->name }}</span></a>
                    @if ($account->is_active && ! $account->isSystem())
                    <form method="POST" action="{{ route('accounting.chart.deactivate', $account) }}" data-fx-confirm="Deactivate {{ $account->code }} {{ $account->name }}? It stays in the books and its history is kept, but nothing new can be posted to it." data-fx-confirm-label="Deactivate">@csrf <button type="submit" class="fx-linkbtn fx-linkbtn--danger">Deactivate<span class="sr-only"> {{ $account->name }}</span></button></form>
                    @elseif (! $account->is_active)
                    <form method="POST" action="{{ route('accounting.chart.activate', $account) }}">@csrf <button type="submit" class="fx-linkbtn">Activate<span class="sr-only"> {{ $account->name }}</span></button></form>
                    @endif
                </td>
                @endif
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th scope="row" colspan="2">Total {{ strtolower($classLabel) }}</th>
                <td class="is-num"><x-fx.money :cents="$classTotal" /></td>
                @if($canManage)<td></td>@endif
            </tr>
        </tfoot>
    </x-fx.table>
</x-fx.panel>
@endforeach
</x-app-layout>
