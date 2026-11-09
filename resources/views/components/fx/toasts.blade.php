{{-- The live region for transient messages. Anything the server wants said for good is rendered in the page (x-fx.flash);
     a toast is for things the browser raises, such as a Livewire action finishing. --}}
<div class="fx-toasts" role="status" aria-live="polite" data-fx-toasts>
    @isset($slot){{ $slot }}@endisset
</div>
