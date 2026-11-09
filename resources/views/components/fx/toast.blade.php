@props(['kind' => 'ok'])
<div {{ $attributes->class(['fx-toast', 'fx-toast--'.$kind]) }} data-fx-toast>
    <x-fx.icon :name="$kind === 'bad' ? 'circle-alert' : 'check'" />
    <p>{{ $slot }}</p>
    <button type="button" class="fx-toast__close" data-fx-toast-close aria-label="Dismiss"><x-fx.icon name="x" /></button>
</div>
