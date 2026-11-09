@if (count($result->checks))
<ul class="rp-checks" aria-label="Reconciliation checks">
    @foreach ($result->checks as $check)
    <li class="rp-check">
        @if ($check['ok'])
        <x-fx.pill kind="ticked">{{ $check['label'] }}</x-fx.pill>
        @else
        @if (array_key_exists('currency', $check) && $check['currency'] === null)
        <x-fx.pill kind="variance">{{ $check['label'] }}: does not agree in more than one currency</x-fx.pill>
        @else
        <x-fx.pill kind="variance">{{ $check['label'] }}: out by <x-fx.money :cents="abs($check['difference_cents'])" :currency="$check['currency'] ?? $result->header['currency']" /></x-fx.pill>
        @endif
        @endif
        <span>{{ $check['detail'] }}</span>
    </li>
    @endforeach
</ul>
@endif
