<x-app-layout title="Command centre">
@php
    $company = $activeLedger?->isCompany();
    $whose = $company ? "the company's" : 'your';
    $monthStart = now()->startOfMonth()->toDateString();
    $monthEnd = now()->endOfMonth()->toDateString();
    $canRecord = auth()->user()->can('create', \App\Models\Transaction::class);
@endphp

<x-fx.page-head title="Command centre" :sub="now()->format('l, j F Y').'. '.($company ? $activeLedger->label() : 'Your own ledger').', '.now()->format('F Y').' to date.'">
    <x-slot name="actions">
        @if ($canRecord)
        <x-fx.button variant="primary" :href="route('transactions.create')" icon="plus">Add Transaction</x-fx.button>
        @endif
    </x-slot>
</x-fx.page-head>

<div class="fx-grid fx-grid--lead">

    {{-- Cash position: the answer this screen exists to give. One block per currency, never a mixed total. --}}
    <x-fx.panel title="Cash position" id="cash-position" :meta="'Balances are derived from '.$whose.' ledger. '.now()->format('F Y').' income and spending exclude transfers.'">
        @if (count($position) > 0)
        <div class="fx-cash">
            @foreach ($position as $code => $row)
            <div class="fx-cash__block">
                <div class="fx-cash__title">
                    <strong>{{ $code }}</strong>
                    <span>{{ $row['accounts'] }} account{{ $row['accounts'] === 1 ? '' : 's' }}</span>
                </div>
                <div class="fx-cash__cols">
                    <div>
                        <p class="fx-hint">Balance</p>
                        <x-fx.money :cents="$row['balance_cents']" :currency="$code" lead :trace="route('accounts.index')" trace-label="See the accounts this balance is made of" />
                    </div>
                    <x-fx.statement aria-label="{{ $code }} for {{ now()->format('F Y') }}">
                        <x-fx.statement-row label="Income this month"><x-fx.money :cents="$row['income_cents']" :currency="$code" :trace="route('transactions.index', ['type' => 'income', 'from' => $monthStart, 'to' => $monthEnd])" trace-label="Show this month's income entries" /></x-fx.statement-row>
                        <x-fx.statement-row label="Spending this month"><x-fx.money :cents="$row['expense_cents']" :currency="$code" :trace="route('transactions.index', ['type' => 'expense', 'from' => $monthStart, 'to' => $monthEnd])" trace-label="Show this month's spending entries" /></x-fx.statement-row>
                        <x-fx.statement-row label="Net this month" kind="total"><x-fx.money :cents="$row['net_cents']" :currency="$code" :signed="true" :trace="route('transactions.index', ['from' => $monthStart, 'to' => $monthEnd])" trace-label="Show every entry this month" /></x-fx.statement-row>
                    </x-fx.statement>
                </div>
            </div>
            @endforeach
        </div>
        @else
        <x-fx.empty :title="$company ? $activeLedger->label().' has no accounts yet.' : 'No accounts yet.'">
            @if ($company)
                @can('create', \App\Models\Account::class)
                Add the first company account with an opening balance and the company ledger takes it from there.
                @else
                A company owner or admin needs to create the first company account.
                @endcan
            @else
            Add an account with an opening balance and the ledger takes it from there.
            @endif
            <x-slot name="action">
                @can('create', \App\Models\Account::class)
                <x-fx.button :href="route('accounts.index')" icon="plus">{{ $company ? 'Create the first company account' : 'Add an account' }}</x-fx.button>
                @endcan
            </x-slot>
        </x-fx.empty>
        @endif
    </x-fx.panel>

    {{-- What needs the owner, in words. The same facts as the line at the top of every page. --}}
    <x-fx.panel title="Needs you" id="needs-you">
        @if (count($needs) > 0)
        <ul class="fx-needs">
            @foreach ($needs as $item)
            <li @class(['fx-needs__item', 'is-variance' => ($item['tone'] ?? '') === 'variance'])>
                <x-fx.icon :name="($item['tone'] ?? '') === 'variance' ? 'circle-alert' : 'info'" />
                <span><span class="fx-needs__count">{{ $item['count'] }}</span> <x-fx.state-item :item="$item" /></span>
            </li>
            @endforeach
        </ul>
        @else
        <div class="fx-ok">
            <x-fx.icon name="check" class="fx-tick-draw" />
            <p><strong>Nothing needs you right now.</strong>
            No budget is over its limit, no repeating item is past its date, and nothing this month is waiting for a category.
            @if (fx_route_exists('reconciliation.index'))
            <a href="{{ route('reconciliation.index') }}" class="fx-link">Check that balances tie out</a>
            @endif
            </p>
        </div>
        @endif

    </x-fx.panel>
</div>

{{-- Cushion: how long reserves last at the current pace (one per currency). --}}
@foreach ($cushions as $code => $cushion)
@if ($cushion['available'])
@php $cushionTitle = ($company ? $activeLedger->label()."'s" : 'Your').' '.$code.' cushion'; @endphp
<x-fx.panel :title="$cushionTitle">
    @if ($cushion['is_growing'])
    <p class="fx-panel__title">{{ $code }} reserves are currently growing, not depleting.</p>
    @else
    <p class="fx-panel__title">Approximately {{ $cushion['months'] }} month{{ $cushion['months'] === 1 ? '' : 's' }} of {{ $code }} reserves at {{ $company ? 'its' : 'your' }} current pace.</p>
    @endif
    <p class="fx-hint">{{ $cushion['basis'] }}</p>
    @if ($cushion['what_if'])
    <p class="fx-hint">If {{ $company ? 'its' : 'your' }} <strong>{{ $cushion['what_if']['category'] }}</strong> spending were eliminated, this would extend to approximately {{ $cushion['what_if']['months_without_category'] }} month{{ $cushion['what_if']['months_without_category'] === 1 ? '' : 's' }}.</p>
    @endif
</x-fx.panel>
@endif
@endforeach

<div class="fx-grid fx-grid--even">

    <x-fx.panel title="Coming up" id="coming-up" meta="Repeating items due in the next 14 days">
        @if ($upcoming->count() > 0)
        <x-fx.table caption="Repeating items due in the next 14 days" class="fx-table--keep">
            <thead>
                <tr>
                    <th scope="col">Due</th>
                    <th scope="col">Item</th>
                    <th scope="col" class="is-num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($upcoming as $rule)
                @php $overdue = $rule->next_occurrence_date->lt(now()->startOfDay()); @endphp
                <tr>
                    <td><span class="fx-date">{{ $rule->next_occurrence_date->format('Y-m-d') }}</span>@if ($overdue)<div class="fx-cell-sub"><x-fx.pill kind="variance">Past its date</x-fx.pill></div>@endif</td>
                    <td><span class="fx-cell-main">{{ $rule->description }}</span><div class="fx-cell-sub">{{ $rule->type === 'income' ? 'Money in' : 'Money out' }}, {{ $rule->account?->name ?? 'no account' }}</div></td>
                    <td class="is-num"><x-fx.money :cents="$rule->type === 'income' ? $rule->amount_cents : -$rule->amount_cents" :currency="$rule->currency" :signed="true" /></td>
                </tr>
                @endforeach
            </tbody>
        </x-fx.table>
        <x-slot name="foot"><a href="{{ route('recurring.index') }}" class="fx-link">All repeating items</a></x-slot>
        @else
        <x-fx.empty title="Nothing is due in the next 14 days.">
            Repeating items (rent, salary, a subscription) appear here a fortnight before they are due. Tick &ldquo;Repeat&rdquo; when you add a transaction.
            <x-slot name="action">
                @if ($canRecord)<x-fx.button :href="route('transactions.create')" icon="plus">Add a repeating item</x-fx.button>@endif
            </x-slot>
        </x-fx.empty>
        @endif
    </x-fx.panel>

    <x-fx.panel title="Budgets this month" id="budgets-this-month">
        @if ($budgets->count() > 0)
        @foreach ($budgets->take(5) as $budget)
        @php
            $spent = $budget->spentCents();
            $limit = $budget->amount_cents;
            $pct = $limit > 0 ? min(100, intdiv($spent * 100, $limit)) : 0;
            $over = $limit > 0 && $spent >= $limit;
        @endphp
        <div class="fx-budgetrow">
            <div class="fx-budgetrow__top">
                <span class="fx-budgetrow__name">{{ $budget->category?->name ?? 'Uncategorized' }} <span class="fx-hint">{{ $budget->currency }}</span></span>
                <span class="fx-budgetrow__fig fx-fig">{{ \App\Support\Money::format($spent) }} / {{ \App\Support\Money::format($limit, $budget->currency) }}</span>
            </div>
            <x-fx.meter :pct="$pct" :variance="$over" :label="$pct.'% of the limit used'" />
            <div class="fx-budgetrow__foot"><span @class(['is-variance' => $over])>{{ $pct }}% used @if ($over)(at or over the limit)@endif</span></div>
        </div>
        @endforeach
        @if ($budgets->count() > 5)<p class="fx-hint">+ {{ $budgets->count() - 5 }} more budgets</p>@endif
        <x-slot name="foot"><a href="{{ route('budgets.index') }}" class="fx-link">Manage budgets</a></x-slot>
        @else
        <x-fx.empty title="No budgets set up yet.">
            A budget caps what a category may spend in a month, and this panel then shows how much of each is used.
            <x-slot name="action">
                @can('create', \App\Models\Budget::class)<x-fx.button :href="route('budgets.index')" icon="plus">Create a budget</x-fx.button>@endcan
            </x-slot>
        </x-fx.empty>
        @endif
    </x-fx.panel>
</div>

{{-- Panels other modules add (overdue invoices, bills due, VAT dates). Each draws nothing when it has no data. --}}
@foreach ($extraPanels as $panelView)
    @include($panelView)
@endforeach

{{-- Spending trend (one currency at a time) --}}
@if ($spendingTrend)
@php
    $trendMonths = $spendingTrend['months'];
    $trendPeak = $spendingTrend['peak_cents'];
    $hasTrendData = collect($trendMonths)->contains(fn ($m) => $m['income_cents'] > 0 || $m['expense_cents'] > 0);
    $chartW = 700; $chartH = 160;
    $slot = $chartW / max(count($trendMonths), 1);
    $barW = $slot * 0.3; $barGap = $slot * 0.06;
@endphp
<x-fx.panel :title="'Spending Trend'" :meta="'In '.$trendCurrency.', month by month'">
    <x-slot name="head">
        <div class="fx-cluster">
            @if (count($currencies) > 1)
            <span class="fx-hint">Currency:
                @foreach ($currencies as $code)
                <a href="{{ route('dashboard', ['currency' => $code]) }}" class="fx-link" @if ($code === $trendCurrency) aria-current="true" @endif>{{ $code }}</a>
                @endforeach
            </span>
            @endif
            <span class="fx-legend"><span><span class="fx-legend__key fx-legend__key--in"></span>Income</span><span><span class="fx-legend__key fx-legend__key--out"></span>Expenses</span></span>
        </div>
    </x-slot>
    @if ($hasTrendData)
    <svg class="fx-chart" viewBox="0 0 {{ $chartW }} {{ $chartH + 26 }}" role="img" aria-label="Income and expenses by month in {{ $trendCurrency }}">
        @foreach ([0.25, 0.5, 0.75, 1] as $fraction)
        <line class="fx-chart__grid" x1="0" y1="{{ $chartH - $chartH * $fraction }}" x2="{{ $chartW }}" y2="{{ $chartH - $chartH * $fraction }}" />
        @endforeach
        <line class="fx-chart__base" x1="0" y1="{{ $chartH }}" x2="{{ $chartW }}" y2="{{ $chartH }}" />
        <text class="fx-chart__tick" x="0" y="-4">{{ \App\Support\Money::format($trendPeak, $trendCurrency) }}</text>
        @foreach ($trendMonths as $i => $month)
        @php
            $slotX = $i * $slot;
            $incomeH = $trendPeak > 0 ? ($month['income_cents'] / $trendPeak) * $chartH : 0;
            $expenseH = $trendPeak > 0 ? ($month['expense_cents'] / $trendPeak) * $chartH : 0;
            $incomeX = $slotX + ($slot / 2) - $barW - ($barGap / 2);
            $expenseX = $slotX + ($slot / 2) + ($barGap / 2);
        @endphp
        <rect class="fx-chart__bar--in" x="{{ round($incomeX, 1) }}" y="{{ round($chartH - $incomeH, 1) }}" width="{{ round($barW, 1) }}" height="{{ round($incomeH, 1) }}"><title>{{ $month['label'] }} income: {{ \App\Support\Money::format($month['income_cents'], $trendCurrency) }}</title></rect>
        <rect class="fx-chart__bar--out" x="{{ round($expenseX, 1) }}" y="{{ round($chartH - $expenseH, 1) }}" width="{{ round($barW, 1) }}" height="{{ round($expenseH, 1) }}"><title>{{ $month['label'] }} expenses: {{ \App\Support\Money::format($month['expense_cents'], $trendCurrency) }}</title></rect>
        <text class="fx-chart__tick" x="{{ round($slotX + $slot / 2, 1) }}" y="{{ $chartH + 18 }}" text-anchor="middle">{{ $month['label'] }}</text>
        @endforeach
    </svg>
    @else
    <x-fx.empty title="No trend to draw yet.">Not enough transaction history yet to chart a trend. It appears once there is income or spending in more than one month.</x-fx.empty>
    @endif
</x-fx.panel>
@else
<x-fx.panel title="Spending Trend">
    <x-fx.empty title="No trend to draw yet.">Not enough transaction history yet to chart a trend.</x-fx.empty>
</x-fx.panel>
@endif

<div class="fx-grid fx-grid--split">

    <x-fx.panel title="Recent Transactions" flush>
        <x-slot name="head"><a href="{{ route('transactions.index') }}" class="fx-link fx-panel__meta">View all</a></x-slot>
        @if ($recentTransactions->count() > 0)
        <x-fx.table caption="The ten most recent transactions">
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Description</th>
                    <th scope="col">Category</th>
                    <th scope="col" class="is-num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recentTransactions as $tx)
                <tr>
                    <td data-label="Date"><span class="fx-date">{{ $tx->date->format('Y-m-d') }}</span></td>
                    <td data-label="Description" class="is-lead"><span class="fx-cell-main">{{ $tx->description }}</span>
                        @if ($tx->isTransfer())<div class="fx-cell-sub">Transfer {{ $tx->transfer_direction === 'out' ? 'out' : 'in' }}</div>@endif</td>
                    <td data-label="Category">{{ $tx->category?->name ?? '—' }}</td>
                    <td data-label="Amount" class="is-num"><x-fx.money :cents="$tx->signedCents() !== 0 ? $tx->signedCents() : $tx->amount_cents" :currency="$tx->currency" :signed="$tx->signedCents() !== 0" /></td>
                </tr>
                @endforeach
            </tbody>
        </x-fx.table>
        @else
        <x-fx.empty title="No transactions yet.">No transactions yet. Add your first transaction to get started.
            <x-slot name="action">@if ($canRecord)<x-fx.button :href="route('transactions.create')" icon="plus">Add a transaction</x-fx.button>@endif</x-slot>
        </x-fx.empty>
        @endif
    </x-fx.panel>

    <div class="fx-stack">
        @if ($accounts->count() > 0)
        <x-fx.panel title="Accounts" flush>
            <x-slot name="head"><a href="{{ route('accounts.index') }}" class="fx-link fx-panel__meta">Open</a></x-slot>
            <x-fx.table caption="Account balances" class="fx-table--keep">
                <thead><tr><th scope="col">Account</th><th scope="col" class="is-num">Balance</th></tr></thead>
                <tbody>
                    @foreach ($accounts as $account)
                    <tr>
                        <th scope="row"><span class="fx-cell-main">{{ $account->name }}</span><div class="fx-cell-sub">{{ ucfirst($account->type) }}@if ($account->institution), {{ $account->institution }}@endif</div></th>
                        <td class="is-num"><x-fx.money :cents="$account->balanceCents()" :currency="$account->currency" :trace="route('transactions.index', ['account_id' => $account->id])" trace-label="Show the entries in {{ $account->name }}" /></td>
                    </tr>
                    @endforeach
                </tbody>
            </x-fx.table>
        </x-fx.panel>
        @endif

        <x-fx.panel title="Rule-based summary">
            @if ($summaryCount > 0)
            <p class="fx-hint">{{ $summaryCount }} summar{{ $summaryCount === 1 ? 'y' : 'ies' }}, one a week, written from {{ $whose }} ledger by fixed rules. Not AI.</p>
            <x-fx.button size="sm" :href="route('insights.index')" icon="file-text">View summaries</x-fx.button>
            @else
            <p class="fx-hint"><strong>No summaries yet.</strong> One is written each week once there is spending history.</p>
            @endif
        </x-fx.panel>
    </div>
</div>

</x-app-layout>
