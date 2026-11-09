{{-- Section navigation for the accounting pages. Plain links; the design system restyles them later. --}}
<nav aria-label="Accounting" class="acct-nav">
    <ul>
        <li><a href="{{ route('accounting.trial-balance') }}" @if(request()->routeIs('accounting.trial-balance')) aria-current="page" @endif>Trial balance</a></li>
        <li><a href="{{ route('accounting.general-ledger') }}" @if(request()->routeIs('accounting.general-ledger')) aria-current="page" @endif>General ledger</a></li>
        <li><a href="{{ route('accounting.journals.index') }}" @if(request()->routeIs('accounting.journals.*')) aria-current="page" @endif>Journals</a></li>
        <li><a href="{{ route('accounting.chart.index') }}" @if(request()->routeIs('accounting.chart.*')) aria-current="page" @endif>Chart of accounts</a></li>
        <li><a href="{{ route('accounting.periods.index') }}" @if(request()->routeIs('accounting.periods.*')) aria-current="page" @endif>Periods</a></li>
    </ul>
</nav>
