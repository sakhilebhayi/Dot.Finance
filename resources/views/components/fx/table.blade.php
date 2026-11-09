@props(['caption', 'scroll' => false, 'keep' => false])
{{-- A ruled table: a caption, <th scope>, a sticky header and, below 720px, a list of cards (every <td> carries its
     data-label). `scroll` is for tables too wide for cards (the tie-out); `keep` stops the card mode altogether. --}}
<div @class(['fx-tablewrap', 'table-scroll', 'fx-tablewrap--scroll' => $scroll])>
    <table {{ $attributes->class(['fx-table', 'fx-table--keep' => $keep || $scroll]) }}>
        <caption class="sr-only">{{ $caption }}</caption>
        {{ $slot }}
    </table>
</div>
