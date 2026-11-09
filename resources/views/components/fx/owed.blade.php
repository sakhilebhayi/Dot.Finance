@props(['cents', 'currency' => null, 'word' => true, 'side' => 'receivable', 'variance' => false, 'lead' => false, 'trace' => null, 'traceLabel' => 'Show the entries this figure is made of'])
@php
    // What someone owes, or the credit they hold, as a positive amount with the right words. A negative receivable is a credit the
    // customer holds: it is shown as "Credit on account 522.00", never as "owes you (522.00)" (D-1703).
    $w = $side === 'payable' ? \App\Support\FigureWords::owedTo((int) $cents) : \App\Support\FigureWords::owedBy((int) $cents);
@endphp
<span {{ $attributes->class(['fx-owed', 'is-credit' => $w['credit']]) }}>
    @if ($w['credit'] && $word)<span class="fx-owed__word">{{ $w['label'] }}</span>@endif
    <x-fx.money :cents="$w['cents']" :currency="$currency" :variance="$variance && ! $w['credit']" :lead="$lead" :trace="$trace" :trace-label="$traceLabel" />
</span>
