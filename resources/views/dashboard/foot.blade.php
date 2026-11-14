{{-- The foot row: live counts and the cushion, once each. --}}
<div class="fxd-foot">
    <p>
        @if ($summaryCount > 0)
        <strong>Rule-based summary.</strong> {{ $summaryCount }} summar{{ $summaryCount === 1 ? 'y' : 'ies' }}, one a week, written from {{ $whose }} ledger by fixed rules. Not AI. <a href="{{ route('insights.index') }}">View summaries</a>
        @else
        <strong>Rule-based summary.</strong> No summaries yet. One is written each week once there is spending history.
        @endif
    </p>
    @foreach ($cushions as $code => $cushion)
    @if ($cushion['available'])
    <p>
        @if ($cushion['is_growing']){{ $code }} reserves are currently growing, not depleting.
        @elseif ($cushion['no_reserves'])There are no {{ $code }} reserves to measure: {{ $company ? 'its' : 'your' }} {{ $code }} checking, savings and cash accounts hold <x-fx.money :cents="$cushion['liquid_reserves_cents']" :currency="$code" /> today.
        @elseif ($cushion['months'] < 1)Less than a month of {{ $code }} reserves at {{ $company ? 'its' : 'your' }} current pace.
        @else About {{ $cushion['months'] }} month{{ $cushion['months'] === 1 ? '' : 's' }} of {{ $code }} reserves at {{ $company ? 'its' : 'your' }} current pace.
        @endif
        {{ $cushion['basis'] }}
        @if ($cushion['what_if'] && ! $cushion['no_reserves'])If {{ $company ? 'its' : 'your' }} <strong>{{ $cushion['what_if']['category'] }}</strong> spending were eliminated, this would extend to approximately {{ $cushion['what_if']['months_without_category'] }} month{{ $cushion['what_if']['months_without_category'] === 1 ? '' : 's' }}.@endif
    </p>
    @endif
    @endforeach
</div>
