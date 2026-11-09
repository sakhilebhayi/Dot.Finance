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
        <x-fx.button variant="primary" :href="route('transactions.create')" icon="plus">Add transaction</x-fx.button>
        @endif
    </x-slot>
</x-fx.page-head>

<div class="fx-grid fx-grid--lead">

    <div class="fx-stack">

    {{-- Cash position: the answer this screen exists to give. One block per currency, never a mixed total. --}}
    <x-fx.panel title="Cash position" id="cash-position" :meta="'Worked out from '.$whose.' ledger, one currency at a time. '.now()->format('F Y').' income and spending leave out transfers.'">
        @if (count($position) > 0)
        <div class="fx-cash">
            @foreach ($currencyOrder as $code)
            @php $row = $position[$code]; @endphp
            <div class="fx-cash__block">
                <div class="fx-cash__title">
                    <strong>{{ $code }}</strong>
                    <span>{{ $row['accounts'] }} account{{ $row['accounts'] === 1 ? '' : 's' }}{{ $loop->first && count($currencyOrder) > 1 ? ', main currency' : '' }}</span>
                </div>
                <div class="fx-cash__cols">
                    <div>
                        <p class="fx-hint">Cash available</p>
                        @if ($row['cash_accounts'] > 0)
                        <x-fx.money :cents="$row['cash_cents']" :currency="$code" lead :trace="route('accounts.index')" trace-label="See the accounts this cash is made of" />
                        <p class="fx-hint fx-cash__def">What is in your {{ $row['cash_accounts'] === 1 ? 'one checking, savings or cash account' : $row['cash_accounts'].' checking, savings and cash accounts' }}. Cards and loans are not taken off it.</p>
                        @else
                        <p class="fx-cash__none">No cash accounts in {{ $code }}</p>
                        @endif
                    </div>
                    <x-fx.statement aria-label="{{ $code }} for {{ now()->format('F Y') }}">
                        <x-fx.statement-row label="Income this month"><x-fx.money :cents="$row['income_cents']" :currency="$code" :trace="route('transactions.index', ['type' => 'income', 'from' => $monthStart, 'to' => $monthEnd])" trace-label="Show this month's income entries" /></x-fx.statement-row>
                        <x-fx.statement-row label="Spending this month"><x-fx.money :cents="$row['expense_cents']" :currency="$code" :trace="route('transactions.index', ['type' => 'expense', 'from' => $monthStart, 'to' => $monthEnd])" trace-label="Show this month's spending entries" /></x-fx.statement-row>
                        <x-fx.statement-row label="Net this month" kind="total"><x-fx.money :cents="$row['net_cents']" :currency="$code" :signed="true" :trace="route('transactions.index', ['from' => $monthStart, 'to' => $monthEnd])" trace-label="Show every entry this month" /></x-fx.statement-row>
                    </x-fx.statement>
                </div>
                @if ($row['bridge'] !== [])
                <div class="fx-cash__bridge">
                    <p class="fx-hint">Not in the cash figure above</p>
                    <ul class="fx-bridge" role="list">
                        @foreach ($row['bridge'] as $b)
                        <li>
                            <span class="fx-bridge__name">{{ $b['name'] }} <span class="fx-hint">{{ \App\Support\FigureWords::accountType($b['type']) }}</span></span>
                            <span class="fx-bridge__fig">
                                @if ($b['type'] === 'credit')
                                    @if ($b['balance_cents'] < 0)owed <x-fx.money :cents="-$b['balance_cents']" :currency="$code" />@elseif ($b['balance_cents'] > 0)credit balance <x-fx.money :cents="$b['balance_cents']" :currency="$code" />@else nothing owed @endif
                                @else
                                    <x-fx.money :cents="$b['balance_cents']" :currency="$code" />
                                @endif
                            </span>
                        </li>
                        @endforeach
                    </ul>
                    @if ($row['owed_cents'] > 0 && $row['cash_accounts'] > 0)
                    <p class="fx-hint">Cash after settling the cards: <x-fx.money :cents="$row['cash_cents'] - $row['owed_cents']" :currency="$code" /></p>
                    @endif
                </div>
                @endif
            </div>
            @endforeach
        </div>
        @else
        <x-fx.empty :title="$company ? $activeLedger->label().' has no accounts yet.' : 'No accounts yet.'">
            @if ($accountingOn)
            The books already hold entries (the profit and loss further down is worked out from them), but no bank, cash or card account is set up here, so there is no cash figure to show.
            @endif
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

    <x-fx.panel title="Coming up" id="coming-up" meta="Repeating items due in the next 14 days">
        @if ($upcoming->count() > 0)
        <x-fx.table caption="Repeating items due in the next 14 days" class="fx-table--compact">
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
                    <td data-label="Due"><span class="fx-date">{{ $rule->next_occurrence_date->format('Y-m-d') }}</span>@if ($overdue)<div class="fx-cell-sub"><x-fx.pill kind="variance">Past its date</x-fx.pill></div>@endif</td>
                    <td data-label="Item" class="is-lead"><span class="fx-cell-main">{{ $rule->description }}</span><div class="fx-cell-sub">{{ $rule->type === 'income' ? 'Money in' : 'Money out' }}</div></td>
                    <td data-label="Amount" class="is-num"><x-fx.money :cents="$rule->type === 'income' ? $rule->amount_cents : -$rule->amount_cents" :currency="$rule->currency" :signed="true" /></td>
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

    </div>

    <div class="fx-stack">
    {{-- What needs the owner, in words. The same facts as the line at the top of every page. --}}
    <x-fx.panel title="Needs you" id="needs-you">
        @if (count($needs) > 0)
        <ul class="fx-needs">
            @foreach ($needs as $item)
            <li @class(['fx-needs__item', 'is-variance' => ($item['tone'] ?? '') === 'variance'])>
                <x-fx.icon :name="($item['tone'] ?? '') === 'variance' ? 'circle-alert' : 'info'" />
                <span><x-fx.state-item :item="$item" /></span>
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

    <x-fx.panel title="Budgets this month" id="budgets-this-month">
        @if ($budgets->count() > 0)
        @foreach ($budgets->take(5) as $budget)
        <x-fx.budget-row :budget="$budget" />
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
{{-- Cushion: how long reserves last at the current pace (one per currency). --}}
@foreach ($cushions as $code => $cushion)
@if ($cushion['available'])
@php $cushionTitle = ($company ? $activeLedger->label()."'s" : 'Your').' '.$code.' cushion'; @endphp
<x-fx.panel :title="$cushionTitle">
    @if ($cushion['is_growing'])
    <p class="fx-panel__title">{{ $code }} reserves are currently growing, not depleting.</p>
    <p class="fx-hint">{{ $cushion['basis'] }}</p>
    @elseif ($cushion['no_reserves'])
    <p class="fx-panel__title">There are no {{ $code }} reserves to measure: {{ $company ? 'its' : 'your' }} {{ $code }} checking, savings and cash accounts hold <x-fx.money :cents="$cushion['liquid_reserves_cents']" :currency="$code" /> today.</p>
    <p class="fx-hint">A number of months is only worked out where there is money to spend down. {{ $code }} spending recently averages <x-fx.money :cents="$cushion['avg_monthly_burn_cents']" :currency="$code" /> a month.</p>
    @else
    <p class="fx-panel__title">@if ($cushion['months'] < 1)Less than a month of {{ $code }} reserves at {{ $company ? 'its' : 'your' }} current pace.@else About {{ $cushion['months'] }} month{{ $cushion['months'] === 1 ? '' : 's' }} of {{ $code }} reserves at {{ $company ? 'its' : 'your' }} current pace.@endif</p>
    @endif
    <p class="fx-hint">{{ $cushion['basis'] }}</p>
    @if ($cushion['what_if'] && ! $cushion['no_reserves'])
    <p class="fx-hint">If {{ $company ? 'its' : 'your' }} <strong>{{ $cushion['what_if']['category'] }}</strong> spending were eliminated, this would extend to approximately {{ $cushion['what_if']['months_without_category'] }} month{{ $cushion['what_if']['months_without_category'] === 1 ? '' : 's' }}.</p>
    @endif
</x-fx.panel>
@endif
@endforeach

    </div>
</div>

{{-- Panels other modules add (overdue invoices, bills due, VAT dates). Each draws nothing when it has no data. --}}
@foreach ($extraPanels as $panelView)
    @include($panelView)
@endforeach

@if (! $accountingOn)
{{-- While the double-entry books are off the module panels above have nothing to read, so say what turning them on adds (D-1711). --}}
<x-fx.panel title="More on this page once accounting is on" id="accounting-off">
    <p class="fx-hint">Cash, spending and budgets above come from your transactions and already work. Switching accounting on adds a profit and loss, what customers owe you, what you owe suppliers, VAT dates and the bank reconciliation to this page, each worked out from balanced journals. Your transactions stay as they are.</p>
    @if (fx_route_exists('accounting.setup'))
    <x-slot name="foot"><a href="{{ route('accounting.setup') }}" class="fx-link">Set up accounting</a></x-slot>
    @endif
</x-fx.panel>
@endif

{{-- Spending trend (one currency at a time) --}}
@if ($spendingTrend)
@php
    $trendMonths = $spendingTrend['months'];
    $trendPeak = $spendingTrend['peak_cents'];
    $hasTrendData = collect($trendMonths)->contains(fn ($m) => $m['income_cents'] > 0 || $m['expense_cents'] > 0);
    $axisW = 52; $chartW = 700; $plotW = $chartW - $axisW; $chartH = 160; $top = 14;
    $slot = $plotW / max(count($trendMonths), 1);
    $barW = $slot * 0.3; $barGap = $slot * 0.06;
    $lastIndex = count($trendMonths) - 1;
    // Round gridline steps (0, 60k, 120k ...) and a top line above the tallest bar, not quarters of the peak.
    $step = \App\Support\FigureWords::niceStep($trendPeak);
    $scaleMax = $step * 4;
@endphp
<x-fx.panel :title="'Spending trend'" :meta="'In '.$trendCurrency.', month by month. The scale starts at zero.'">
    <x-slot name="head">
        <div class="fx-cluster">
            @if (count($currencies) > 1)
            <div class="fx-seg" role="group" aria-label="Currency for this chart">
                @foreach ($currencies as $code)
                <a href="{{ route('dashboard', ['currency' => $code]) }}" class="fx-seg__opt" @if ($code === $trendCurrency) aria-current="true" @endif>{{ $code }}</a>
                @endforeach
            </div>
            @endif
            <span class="fx-legend"><span><span class="fx-legend__key fx-legend__key--in"></span>Income</span><span><span class="fx-legend__key fx-legend__key--out"></span>Spending</span></span>
        </div>
    </x-slot>
    @if ($hasTrendData)
    <svg class="fx-chart" viewBox="0 0 {{ $chartW }} {{ $top + $chartH + 26 }}" role="img" aria-label="Income and spending by month in {{ $trendCurrency }}">
        @foreach ([0, 1, 2, 3, 4] as $quarter)
        @php $gy = $top + $chartH - $chartH * $quarter / 4; @endphp
        <line @class(['fx-chart__grid', 'fx-chart__base' => $quarter === 0]) x1="{{ $axisW }}" y1="{{ $gy }}" x2="{{ $chartW }}" y2="{{ $gy }}" />
        <text class="fx-chart__tick" x="{{ $axisW - 8 }}" y="{{ $gy + 4 }}" text-anchor="end">{{ \App\Support\FigureWords::axis($step * $quarter) }}</text>
        @endforeach
        @foreach ($trendMonths as $i => $month)
        @php
            $slotX = $axisW + $i * $slot;
            $incomeH = $scaleMax > 0 ? ($month['income_cents'] / $scaleMax) * $chartH : 0;
            $expenseH = $scaleMax > 0 ? ($month['expense_cents'] / $scaleMax) * $chartH : 0;
            $incomeX = $slotX + ($slot / 2) - $barW - ($barGap / 2);
            $expenseX = $slotX + ($slot / 2) + ($barGap / 2);
        @endphp
        <rect class="fx-chart__bar--in" x="{{ round($incomeX, 1) }}" y="{{ round($top + $chartH - $incomeH, 1) }}" width="{{ round($barW, 1) }}" height="{{ round($incomeH, 1) }}"><title>{{ $month['label'] }} income: {{ \App\Support\Money::format($month['income_cents'], $trendCurrency) }}</title></rect>
        <rect class="fx-chart__bar--out" x="{{ round($expenseX, 1) }}" y="{{ round($top + $chartH - $expenseH, 1) }}" width="{{ round($barW, 1) }}" height="{{ round($expenseH, 1) }}"><title>{{ $month['label'] }} spending: {{ \App\Support\Money::format($month['expense_cents'], $trendCurrency) }}</title></rect>
        @if ($i === $lastIndex)
        {{-- Direct labels on the newest month, the one the reader came to compare against. --}}
        <text class="fx-chart__tick fx-chart__direct" x="{{ round($incomeX + $barW / 2, 1) }}" y="{{ round($top + $chartH - $incomeH - 4, 1) }}" text-anchor="middle">{{ \App\Support\FigureWords::axis($month['income_cents']) }}</text>
        <text class="fx-chart__tick fx-chart__direct" x="{{ round($expenseX + $barW / 2, 1) }}" y="{{ round($top + $chartH - $expenseH - 4, 1) }}" text-anchor="middle">{{ \App\Support\FigureWords::axis($month['expense_cents']) }}</text>
        @endif
        <text class="fx-chart__tick" x="{{ round($slotX + $slot / 2, 1) }}" y="{{ $top + $chartH + 18 }}" text-anchor="middle">{{ $month['label'] }}</text>
        @endforeach
    </svg>
    <p class="fx-hint">Figures on the left are in {{ $trendCurrency }} (k is thousands, m is millions). The newest month is labelled and still running; point at any bar for its exact amount.</p>
    @else
    <x-fx.empty title="No trend to draw yet.">Not enough transaction history yet to chart a trend. It appears once there is income or spending in more than one month.</x-fx.empty>
    @endif
</x-fx.panel>
@else
<x-fx.panel title="Spending trend">
    <x-fx.empty title="No trend to draw yet.">Not enough transaction history yet to chart a trend.</x-fx.empty>
</x-fx.panel>
@endif

<div class="fx-grid fx-grid--split">

    <x-fx.panel title="Recent transactions" flush>
        <x-slot name="head"><a href="{{ route('transactions.index') }}" class="fx-link fx-panel__meta">View all</a></x-slot>
        @if ($recentTransactions->count() > 0)
        <x-fx.table caption="The ten most recent transactions" class="fx-table--compact">
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
                        <th scope="row"><span class="fx-cell-main">{{ $account->name }}</span><div class="fx-cell-sub">{{ \App\Support\FigureWords::accountType($account->type) }}@if ($account->institution), {{ $account->institution }}@endif</div></th>
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
