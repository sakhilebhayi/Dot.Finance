<button {{ $attributes->merge(['type' => 'button', 'class' => 'fx-btn fx-btn--danger']) }}>
    {{ $slot }}
</button>
