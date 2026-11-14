{{-- Panels other modules add (profit and loss, what customers owe, bills, VAT dates, the bank). Each draws nothing when it has no
     data; here they are flattened into ruled sections, and while accounting is off one honest row says what is missing. --}}
@if ($extraPanels !== [] || ! $accountingOn)
<section class="fxd-sec fxd-arrive fxd-books" id="from-the-books" aria-labelledby="fxd-books-h">
    <div class="fxd-sec__h"><h2 id="fxd-books-h">From the books</h2></div>
    @foreach ($extraPanels as $panelView)
        @include($panelView)
    @endforeach
    @if (! $accountingOn)
    <p class="fxd-ok" id="accounting-off">
        <x-fx.icon name="info" />
        <span>Accounting is off for this ledger, so profit and loss, what you are owed, supplier bills, tax dates and the bank reconciliation are not shown. Cash, spending and budgets come from your transactions and already work.
        @if (fx_route_exists('accounting.setup'))<a href="{{ route('accounting.setup') }}">Set up accounting</a>@endif</span>
    </p>
    @endif
</section>
@endif
