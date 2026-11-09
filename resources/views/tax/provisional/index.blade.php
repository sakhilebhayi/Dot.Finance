<x-app-layout title="Provisional tax">
    <x-fx.page-head title="Provisional tax" sub="Your own estimates, the payments they imply, and the penalty tests. Always an estimate.">
        <x-slot name="actions">
            <form method="GET" action="{{ route('tax.provisional.index') }}" class="fx-cluster"><label class="sr-only" for="ye">Year ending</label><input id="ye" type="date" name="year_end" class="fx-input" value="{{ $yearEnd->toDateString() }}"><x-fx.button>Show</x-fx.button></form>
        </x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash /><x-fx.errors />

    @unless ($applies)
        <x-fx.empty title="Provisional tax is not tracked for this business.">The profile is on turnover tax, or the entity is one this tool does not run a schedule for. See what applies for the reasons.</x-fx.empty>
    @else
    <p><x-fx.pill>ESTIMATE</x-fx.pill> Year ending {{ $yearEnd->format('j F Y') }}. The first payment is half the estimated year's tax; the second is the full-year estimate less the first; the third is an optional top-up with no underestimation penalty. Credits (employees' tax, foreign tax) are not modelled.</p>

    @if ($estimate)
    <x-fx.panel title="A starting point for your estimate" meta="Taxable income to date and a straight-line projection">
        <x-fx.statement>
            <x-fx.statement-row label="Taxable income so far (estimate)"><x-fx.money :cents="$estimate->taxableIncomeCents" currency="ZAR" /> <x-tax.basis basis="estimate" /></x-fx.statement-row>
            <x-fx.statement-row label="If the rest of the year looks like the part so far"><x-fx.money :cents="$estimate->projectedTaxableIncomeCents" currency="ZAR" /> <x-tax.basis basis="suggestion" /></x-fx.statement-row>
        </x-fx.statement>
        <p class="fx-hint">The estimate you file is yours. It may not be lower than the basic amount (your last assessed taxable income, less any taxable capital gain), which grows 8 percent when the estimate is made more than 18 months after that year ended. Dot.Finance applies the 8 percent once; whether it compounds for each extra year is not settled in our sources.</p>
    </x-fx.panel>
    @endif

    <x-fx.panel title="The three payments" flush>
        <x-fx.table caption="Provisional tax periods">
            <thead><tr><th scope="col">Payment</th><th scope="col">Due</th><th scope="col">Your estimate</th><th scope="col">Amount due</th><th scope="col">Paid</th></tr></thead>
            <tbody>
            @foreach ($schedule as $s)
                @php $row = $s['row']; @endphp
                <tr>
                    <td data-label="Payment" class="is-lead">{{ $s['label'] }}<span class="fx-cell-sub">{{ $s['due']->note() }}</span></td>
                    <td data-label="Due">{{ $s['due']->due->format('D j M Y') }} <x-fx.pill :kind="$s['status'] === 'overdue' ? 'variance' : ($s['status'] === 'paid' ? 'ticked' : 'plain')">{{ ucfirst($s['status']) }}</x-fx.pill></td>
                    <td data-label="Your estimate" class="is-num">@if($row?->estimate_taxable_income_cents !== null)<x-fx.money :cents="$row->estimate_taxable_income_cents" currency="ZAR" /> <x-tax.basis basis="entered" />@else<span class="fx-hint">not entered</span>@endif</td>
                    <td data-label="Amount due" class="is-num">@if($row?->amount_due_cents !== null)<x-fx.money :cents="$row->amount_due_cents" currency="ZAR" /> <x-tax.basis basis="estimate" />@else<span class="fx-hint">enter an estimate</span>@endif</td>
                    <td data-label="Paid" class="is-num">@if($row?->paid_on)<x-fx.money :cents="$row->paid_cents" currency="ZAR" />, {{ $row->paid_on->format('j M Y') }}@if($s['late_penalty_cents'] > 0)<span class="fx-cell-sub">Late: a 10 percent penalty of <x-fx.money :cents="$s['late_penalty_cents']" currency="ZAR" variance /> may apply</span>@endif @else<span class="fx-hint">not recorded</span>@endif</td>
                </tr>
                @if ($canPrepare)
                <tr>
                    <td data-label="Update" colspan="5">
                        <form method="POST" action="{{ route('tax.provisional.estimate') }}" class="fx-cluster">@csrf
                            <input type="hidden" name="year_end" value="{{ $yearEnd->toDateString() }}"><input type="hidden" name="period" value="{{ $s['period'] }}">
                            <label class="sr-only" for="e{{ $s['period'] }}">Estimate of taxable income for the year, period {{ $s['period'] }}</label><input id="e{{ $s['period'] }}" name="estimate" class="fx-input fx-input--figure" required placeholder="Taxable income estimate" value="{{ $row?->estimate_taxable_income_cents !== null ? \App\Support\Money::toDecimalString($row->estimate_taxable_income_cents) : '' }}">
                            <label class="sr-only" for="b{{ $s['period'] }}">Basic amount</label><input id="b{{ $s['period'] }}" name="basic_amount" class="fx-input fx-input--figure" placeholder="Basic amount (optional)" value="{{ $row?->basic_amount_cents !== null ? \App\Support\Money::toDecimalString($row->basic_amount_cents) : '' }}">
                            <x-fx.button size="sm">Save estimate</x-fx.button>
                        </form>
                        @if ($row && $canRecord)
                        <form method="POST" action="{{ route('tax.provisional.payment', $row) }}" class="fx-cluster">@csrf
                            <label class="sr-only" for="p{{ $s['period'] }}">Amount paid</label><input id="p{{ $s['period'] }}" name="amount" class="fx-input fx-input--figure" required placeholder="Amount paid">
                            <label class="sr-only" for="d{{ $s['period'] }}">Date paid</label><input id="d{{ $s['period'] }}" type="date" name="paid_on" class="fx-input" required value="{{ $today->toDateString() }}">
                            <label class="sr-only" for="f{{ $s['period'] }}">Reference</label><input id="f{{ $s['period'] }}" name="reference" class="fx-input" placeholder="Reference (optional)">
                            <x-fx.button size="sm">Record payment</x-fx.button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endif
            @endforeach
            </tbody>
        </x-fx.table>
    </x-fx.panel>

    @if ($penalty)
    <x-fx.panel title="Underestimation penalty test" :meta="$penalty['actual_is_final'] ? 'Against this year\'s taxable income' : 'Against the year to date. The year is not over: this is a preview'">
        <x-fx.statement>
            <x-fx.statement-row label="Rule that applies">{{ $penalty['rule'] }}</x-fx.statement-row>
            <x-fx.statement-row label="Your estimate must reach"><x-fx.money :cents="$penalty['trigger_cents']" currency="ZAR" /></x-fx.statement-row>
            <x-fx.statement-row label="Penalty (estimate)" kind="total"><x-fx.money :cents="$penalty['penalty_cents']" currency="ZAR" :variance="$penalty['applies']" :ticked="! $penalty['applies']" /></x-fx.statement-row>
        </x-fx.statement>
        <p class="fx-hint">{{ $penalty['explanation'] }}</p>
        <x-tax.basis basis="estimate" />
    </x-fx.panel>
    @endif
    @endunless
    @include('tax._footer')
</x-app-layout>
