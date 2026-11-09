<x-app-layout title="Income tax">
    <x-fx.page-head title="Income tax" sub="An estimate for the tax year, built from your profit and loss. It is not a return and SARS may assess differently.">
        <x-slot name="actions">
            @if ($canExport && $estimate)
                <x-fx.button :href="route('tax.income.workpaper', ['year_end' => $yearEnd->toDateString()])" icon="file-text">Working paper</x-fx.button>
                <x-fx.button :href="route('tax.income.csv', ['year_end' => $yearEnd->toDateString()])" icon="download">CSV</x-fx.button>
            @endif
        </x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash /><x-fx.errors />

    @if ($error)
        <div class="fx-alert" role="alert"><x-fx.icon name="circle-alert" /><p>{{ $error }}</p></div>
    @elseif ($estimate)
    <p class="tax-banner__line"><x-fx.pill>ESTIMATE, NOT A RETURN</x-fx.pill> Tax year {{ \Carbon\CarbonImmutable::parse($estimate->taxYearStart)->format('j M Y') }} to {{ \Carbon\CarbonImmutable::parse($estimate->taxYearEnd)->format('j M Y') }}{{ $estimate->yearComplete ? '' : ', year to date' }}. Profit is read from {{ $estimate->sourceText() }}. Rules confidence: {{ $estimate->confidence }}.</p>

    @foreach ($estimate->warnings as $w)<div class="fx-alert" role="status"><x-fx.icon name="info" /><p>{{ $w }}</p></div>@endforeach

    <div class="fx-grid fx-grid--lead">
        <x-fx.panel :title="$estimate->regimeLabel" meta="Line by line, with how each figure came to be">
            <x-fx.statement>
                @foreach ($estimate->lines as $l)
                    <x-fx.statement-row :label="$l['label']" :kind="in_array($l['label'], ['Accounting profit', 'Taxable income (estimate)'], true) ? 'subtotal' : (str_starts_with($l['label'], 'Income tax') ? 'total' : null)">
                        <x-fx.money :cents="$l['cents']" currency="ZAR" /> <x-tax.basis :basis="$l['basis']" />
                    </x-fx.statement-row>
                @endforeach
            </x-fx.statement>
            @if (! $estimate->yearComplete)
            <p class="fx-hint">If the rest of the year looks like the part so far, taxable income would be about <x-fx.money :cents="$estimate->projectedTaxableIncomeCents" currency="ZAR" /> and tax about <x-fx.money :cents="$estimate->projectedTaxCents" currency="ZAR" />. <x-tax.basis basis="suggestion" /></p>
            @endif
            @if ($canPrepare)
            <x-slot name="foot">
                <form method="POST" action="{{ route('tax.income.save') }}" class="fx-cluster">@csrf
                    <input type="hidden" name="year_end" value="{{ $yearEnd->toDateString() }}">
                    <label class="sr-only" for="lbl">Label</label><input id="lbl" name="label" class="fx-input" maxlength="120" placeholder="Label for this snapshot (optional)">
                    <x-fx.button>Save this estimate</x-fx.button>
                </form>
            </x-slot>
            @endif
        </x-fx.panel>

        @php $parts = $estimate->bySource(); @endphp
        <x-fx.panel title="Where the profit comes from" meta="Read from {{ $estimate->source === 'books' ? 'the books' : 'the cash ledger' }}">
            @if ($estimate->source === 'books' && ($parts['income'] !== [] || $parts['expense'] !== []))
            <x-fx.statement>
                @foreach ($parts['income'] as $row)<x-fx.statement-row :label="'Income: '.$row['label']"><x-fx.money :cents="$row['cents']" currency="ZAR" /> <x-tax.basis basis="calculated" /></x-fx.statement-row>@endforeach
                @foreach ($parts['expense'] as $row)<x-fx.statement-row :label="'Expenses: '.$row['label']"><x-fx.money :cents="-$row['cents']" currency="ZAR" /> <x-tax.basis basis="calculated" /></x-fx.statement-row>@endforeach
            </x-fx.statement>
            <p class="fx-hint">The tax estimate reads the books, so invoices and bills count when they are issued. The Transactions list holds only money that moved, so the two can show different totals for the same dates.</p>
            @elseif ($estimate->source === 'books')
            <p class="fx-hint">There are no income or expense entries in the books for this tax year to date.</p>
            @else
            <p class="fx-hint">Accounting is not switched on, so income and expenses are the ones recorded in the Transactions list.</p>
            @endif
            <x-slot name="foot"><a class="fx-link" href="{{ route('reports.profit-loss') }}">Open the Profit and loss report</a></x-slot>
        </x-fx.panel>

        <x-fx.panel title="Not in this estimate" meta="Ask your practitioner">
            <ul class="fx-list">@foreach ($estimate->notModelled as $n)<li>{{ $n }}</li>@endforeach</ul>
        </x-fx.panel>
    </div>

    <x-fx.panel title="Review prompts" meta="Expense accounts whose names match a point worth checking. Suggestions, not advice." flush>
        @if (count($estimate->flagged) === 0)<x-fx.empty title="Nothing flagged.">No expense account name matches a review prompt. That is a name match only: it does not mean every expense is deductible.</x-fx.empty>@else
        <x-fx.table caption="Expense accounts to review">
            <thead><tr><th scope="col">Account</th><th scope="col">Spent</th><th scope="col">Worth checking</th><th scope="col">Your decision</th></tr></thead>
            <tbody>
            @foreach ($estimate->flagged as $f)
                <tr>
                    <td data-label="Account" class="is-lead">{{ $f['code'] }} {{ $f['name'] }}<span class="fx-cell-sub">{{ $f['label'] }}</span></td>
                    <td data-label="Spent" class="is-num"><x-fx.money :cents="$f['amount_cents']" currency="ZAR" /></td>
                    <td data-label="Worth checking">{{ $f['income_tax'] }}@if($f['vat'])<span class="fx-cell-sub">VAT: {{ $f['vat'] }}</span>@endif<span class="fx-cell-sub">{{ $f['refs'] }}</span> <x-tax.basis basis="suggestion" /></td>
                    <td data-label="Your decision">
                        @if ($f['decision'])<x-fx.pill kind="ticked">Looked at</x-fx.pill>
                        @elseif ($canPrepare)
                        <form method="POST" action="{{ route('tax.income.adjust') }}" class="fx-stack">@csrf
                            <input type="hidden" name="tax_year_end" value="{{ $estimate->taxYearEnd }}"><input type="hidden" name="gl_account_id" value="{{ $f['gl_id'] }}"><input type="hidden" name="category" value="{{ $f['key'] }}"><input type="hidden" name="label" value="{{ $f['name'] }}">
                            <label class="sr-only" for="k{{ $f['gl_id'] }}{{ $f['key'] }}">Decision</label>
                            <select id="k{{ $f['gl_id'] }}{{ $f['key'] }}" name="kind" class="fx-select"><option value="add_back">Add back (not deductible)</option><option value="review_only">Looked at, no adjustment</option></select>
                            <label class="sr-only" for="a{{ $f['gl_id'] }}{{ $f['key'] }}">Amount to add back</label><input id="a{{ $f['gl_id'] }}{{ $f['key'] }}" name="amount" class="fx-input fx-input--figure" value="{{ \App\Support\Money::toDecimalString($f['amount_cents']) }}">
                            <label class="sr-only" for="r{{ $f['gl_id'] }}{{ $f['key'] }}">Reason</label><input id="r{{ $f['gl_id'] }}{{ $f['key'] }}" name="reason" class="fx-input" required minlength="5" maxlength="500" placeholder="Reason">
                            <x-fx.button size="sm">Record</x-fx.button>
                        </form>
                        @else<span class="fx-hint">Open</span>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        @endif
    </x-fx.panel>

    <x-fx.panel title="Adjustments you entered" meta="Each has a reason; none is deleted" flush>
        @if (count($estimate->adjustments) === 0)<x-fx.empty title="None yet.">Enter an add-back, an extra deduction (such as wear and tear), exempt income, or an assessed loss brought forward.</x-fx.empty>@else
        <x-fx.table caption="Income tax adjustments">
            <thead><tr><th scope="col">What</th><th scope="col">Kind</th><th scope="col">Amount</th><th scope="col">Reason</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
            <tbody>@foreach ($estimate->adjustments as $a)
                <tr>
                    <td data-label="What" class="is-lead">{{ $a['label'] }}</td><td data-label="Kind">{{ $adjustmentKinds[$a['kind']] ?? $a['kind'] }}</td>
                    <td data-label="Amount" class="is-num"><x-fx.money :cents="$a['amount_cents']" currency="ZAR" /></td><td data-label="Reason">{{ $a['reason'] }} <x-tax.basis basis="entered" /></td>
                    <td data-label="" class="is-actions">@if ($canPrepare)<form method="POST" action="{{ route('tax.income.adjust.void', $a['id']) }}" class="fx-cluster">@csrf<label class="sr-only" for="vv{{ $a['id'] }}">Reason for voiding</label><input id="vv{{ $a['id'] }}" name="reason" class="fx-input" required minlength="3" placeholder="Why void it"><x-fx.button size="sm">Void</x-fx.button></form>@endif</td>
                </tr>@endforeach</tbody>
        </x-fx.table>
        @endif
        @if ($canPrepare)
        <x-slot name="foot">
            <form method="POST" action="{{ route('tax.income.adjust') }}" enctype="multipart/form-data" class="fx-form fx-stack">@csrf
                <input type="hidden" name="tax_year_end" value="{{ $estimate->taxYearEnd }}">
                <div class="fx-row fx-row--3">
                    <x-fx.select name="kind" label="Kind">@foreach ($adjustmentKinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</x-fx.select>
                    <x-fx.input name="label" label="What is it" />
                    <x-fx.input name="amount" label="Amount (ZAR)" figure />
                </div>
                <x-fx.input name="reason" label="Reason" hint="For a deduction, say which section or allowance." />
                <x-fx.input name="file" label="Supporting file" type="file" optional />
                <div class="fx-formactions"><x-fx.button>Record the adjustment</x-fx.button></div>
            </form>
        </x-slot>
        @endif
    </x-fx.panel>

    @if ($provision)
    <x-fx.panel title="Income tax provision" meta="A suggested journal. Nothing is posted until you confirm.">
        <x-fx.statement>
            <x-fx.statement-row label="Estimated tax for the year"><x-fx.money :cents="$provision['estimate_cents']" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="Already provided by earlier journals"><x-fx.money :cents="$provision['already_provided_cents']" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="To provide now" kind="total"><x-fx.money :cents="$provision['amount_cents']" currency="ZAR" signed /></x-fx.statement-row>
        </x-fx.statement>
        <x-tax.basis basis="suggestion" />
        @if ($provision['amount_cents'] !== 0 && $canPrepare && $canPost)
        <form method="POST" action="{{ route('tax.income.provision') }}" class="fx-form fx-stack">@csrf
            <input type="hidden" name="year_end" value="{{ $yearEnd->toDateString() }}">
            <x-fx.select name="expense_account_id" label="Income tax expense account" hint="Choose an expense account. Create one in the chart if you have none.">
                @unless ($defaultExpense)<option value="" selected>Choose an account</option>@endunless
                @foreach ($expenseAccounts as $a)<option value="{{ $a->id }}" @selected($defaultExpense?->id === $a->id)>{{ $a->label() }}</option>@endforeach
            </x-fx.select>
            <x-fx.check name="confirm">I confirm this provision is an estimate and should be posted</x-fx.check>
            <div class="fx-formactions"><x-fx.button>Post the provision</x-fx.button></div>
        </form>
        @endif
    </x-fx.panel>
    @endif

    @if ($saved->isNotEmpty())
    <x-fx.panel title="Saved estimates" meta="Snapshots, newest first" flush>
        <x-fx.table caption="Saved income tax estimates">
            <thead><tr><th scope="col">Saved</th><th scope="col">Label</th><th scope="col">Taxable income</th><th scope="col">Tax</th></tr></thead>
            <tbody>@foreach ($saved as $s)<tr><td data-label="Saved" class="is-lead">{{ $s->created_at?->format('j M Y H:i') }}</td><td data-label="Label">{{ $s->label }}</td><td data-label="Taxable income" class="is-num"><x-fx.money :cents="$s->taxable_income_cents" currency="ZAR" /></td><td data-label="Tax" class="is-num"><x-fx.money :cents="$s->tax_cents" currency="ZAR" /></td></tr>@endforeach</tbody>
        </x-fx.table>
    </x-fx.panel>
    @endif

    @include('tax._rules', ['versions' => $estimate->ruleVersions])
    @endif
    @include('tax._footer')
</x-app-layout>
