{{-- What needs the person, in words: the same facts as the line at the top of every page. One row when there is nothing. --}}
<section class="fxd-sec fxd-arrive" id="needs-you" aria-labelledby="fxd-needs-h">
    <div class="fxd-sec__h"><h2 id="fxd-needs-h">Needs you</h2><p>Same facts as the line at the top</p></div>
    @if (count($needs) > 0)
    <ul class="fxd-list fxd-rows" role="list">
        @foreach ($needs as $item)
        <li @class(['fxd-need', 'is-variance' => ($item['tone'] ?? '') === 'variance', 'fxd-row-link' => ! empty($item['href'])])>
            <x-fx.icon :name="($item['tone'] ?? '') === 'variance' ? 'circle-alert' : 'info'" />
            <span><x-fx.state-item :item="$item" /></span>
        </li>
        @endforeach
    </ul>
    @else
    <p class="fxd-ok">
        <x-fx.icon name="check" />
        <span>Nothing needs you right now. @if (fx_route_exists('reconciliation.index'))<a href="{{ route('reconciliation.index') }}">Check that balances tie out</a>@endif</span>
    </p>
    @endif
</section>
