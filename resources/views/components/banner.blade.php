@props(['style' => session('flash.bannerStyle', 'success'), 'message' => session('flash.banner')])

{{-- Jetstream raises "banner-message" browser events after some Livewire actions. Shown as a toast, hidden with x-cloak. --}}
<div x-data="{{ json_encode(['show' => true, 'style' => $style, 'message' => $message]) }}"
    x-cloak
    x-show="show && message"
    x-on:banner-message.window="
        style = event.detail.style;
        message = event.detail.message;
        show = true;
    "
    class="fx-banner"
    role="status">
    <p x-text="message"></p>
    <button type="button" class="fx-toast__close" aria-label="Dismiss" x-on:click="show = false"><x-fx.icon name="x" /></button>
</div>
