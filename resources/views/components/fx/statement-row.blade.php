@props(['label', 'kind' => null])
<div @class(['fx-statement__row', 'is-subtotal' => $kind === 'subtotal', 'is-total' => $kind === 'total'])>
    <dt>{{ $label }}</dt>
    <span class="fx-statement__leader" aria-hidden="true"></span>
    <dd>{{ $slot }}</dd>
</div>
