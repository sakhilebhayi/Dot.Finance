@if (session('status'))
<div {{ $attributes->class(['fx-alert', 'fx-alert--ok']) }} role="status">
    <x-fx.icon name="check" class="fx-tick-draw" />
    <p>{{ session('status') }}</p>
</div>
@endif
