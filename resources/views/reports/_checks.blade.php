@if (count($result->checks))
<ul class="rp-checks" aria-label="Reconciliation checks">
    @foreach ($result->checks as $check)
    <li class="rp-check">
        @if ($check['ok'])
        <x-fx.pill kind="ticked">{{ $check['label'] }}</x-fx.pill>
        @else
        <x-fx.pill kind="variance">{{ $check['label'] }}: out by <x-fx.money :cents="abs($check['difference_cents'])" :currency="$result->header['currency']" /></x-fx.pill>
        @endif
        <span>{{ $check['detail'] }}</span>
    </li>
    @endforeach
</ul>
@endif
