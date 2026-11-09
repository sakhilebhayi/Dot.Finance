<x-app-layout title="Purchases">
<x-fx.page-head title="Purchases" sub="What you owe, what is due, and what is missing paper. Every figure is read from the books.">
    <x-slot name="actions">
        @if ($canBill)<x-fx.button :href="route('purchases.bills.create')" variant="primary" icon="plus">New bill</x-fx.button>@endif
        @if ($canPay)<x-fx.button :href="route('purchases.runs.create')" icon="send">Payment run</x-fx.button>@endif
    </x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />

@if (! $on)
<x-fx.panel title="Turn on accounting to post bills">
    <p>Bills are posted to the books when they are approved, so the accounts-payable ledger always agrees with the books. Accounting is not switched on for this ledger yet.</p>
    <x-slot name="foot"><x-fx.button :href="route('accounting.setup')" variant="primary">Set up accounting</x-fx.button></x-slot>
</x-fx.panel>
@endif

@if ($settings->vat_registered === null)
<x-fx.panel title="Are you registered for VAT?">
    <p>Until you say, no VAT on a bill is claimed as input tax: it goes into the cost. <a class="fx-link" href="{{ route('purchases.settings.edit') }}">Tell us under Purchases settings.</a></p>
</x-fx.panel>
@endif

<div class="fx-grid fx-grid--lead">
    <x-fx.panel title="Owed to suppliers" :meta="'Ages measured today, in '.$base">
        @if ($ageing['rows'] === [])
            <x-fx.empty title="Nothing owed.">Posted bills that are still unpaid appear here, aged by how late they are.</x-fx.empty>
        @else
        <x-fx.statement>
            @foreach (\App\Services\Purchases\ApLedger::BUCKETS as $bucket)
                @if ($ageing['totals'][$bucket] !== 0)
                <x-fx.statement-row :label="\App\Services\Purchases\ApLedger::BUCKET_LABELS[$bucket]"><x-fx.money :cents="$ageing['totals'][$bucket]" :currency="$base" :variance="$bucket !== 'current'" /></x-fx.statement-row>
                @endif
            @endforeach
            <x-fx.statement-row label="Total owed" kind="total"><x-fx.money :cents="$ageing['grand_total']" :currency="$base" :trace="route('purchases.ap.index')" trace-label="Show the supplier ledger" /></x-fx.statement-row>
        </x-fx.statement>
        @endif
        <x-slot name="foot">
            @if ($reconcile['accounting_on'])
                @if ($reconcile['ok'])
                    <x-fx.pill kind="ticked">Agrees with the books</x-fx.pill>
                @else
                    <x-fx.pill kind="variance">Differs from the books by <x-fx.money :cents="$reconcile['difference_cents']" :currency="$base" /></x-fx.pill>
                    <a class="fx-link" href="{{ route('purchases.ap.index') }}">See which supplier</a>
                @endif
            @endif
        </x-slot>
    </x-fx.panel>

    <x-fx.panel title="Needs you">
        <ul class="fx-list">
            <li><a class="fx-link" href="{{ route('purchases.bills.index', ['status' => 'draft']) }}">{{ $drafts }} draft {{ \Illuminate\Support\Str::plural('bill', $drafts) }}</a></li>
            <li><a class="fx-link" href="{{ route('controls.approvals.index') }}">{{ $awaiting }} waiting for approval</a></li>
            <li><a class="fx-link" href="{{ route('purchases.bills.index', ['status' => 'approved']) }}">{{ $unposted }} approved but not posted</a></li>
            <li><a class="fx-link" href="{{ route('purchases.documents.index') }}">{{ $missingCount }} {{ \Illuminate\Support\Str::plural('record', $missingCount) }} without a document</a></li>
        </ul>
    </x-fx.panel>
</div>

<div class="fx-grid fx-grid--even">
    <x-fx.panel title="Overdue" flush>
        @include('purchases._bill-rows', ['rows' => $overdue, 'empty' => 'Nothing is overdue.', 'overdue' => true])
    </x-fx.panel>
    <x-fx.panel title="Due in the next 14 days" flush>
        @include('purchases._bill-rows', ['rows' => $dueSoon, 'empty' => 'Nothing falls due in the next two weeks.', 'overdue' => false])
    </x-fx.panel>
</div>

@if ($movers->isNotEmpty())
<x-fx.panel title="Where spending moved this month" meta="Expense accounts in the books, this month against last" flush>
    @include('purchases._movers', ['movers' => $movers, 'base' => $base])
</x-fx.panel>
@endif
</x-app-layout>
