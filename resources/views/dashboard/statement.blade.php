{{-- This month, one column per currency, never summed. The lead currency and up to two more are columns; the rest sit in a
     disclosure with the same rows. Each figure keeps the tick that opens the entries it was added from. --}}
@php
    $shown = array_slice($currencyOrder, 0, 3);
    $more = array_slice($currencyOrder, 3);
@endphp
<section class="fxd-sec fxd-arrive" aria-labelledby="fxd-month-h" id="cash-position">
    <div class="fxd-sec__h"><h2 id="fxd-month-h">{{ now()->format('F Y') }} to date</h2><p>Transfers are left out</p></div>
    @include('dashboard.statement-table', ['codes' => $shown])
    @if ($more !== [])
    <details class="fxd-more">
        <summary>Other currencies ({{ count($more) }})</summary>
        @include('dashboard.statement-table', ['codes' => $more])
    </details>
    @endif
</section>
