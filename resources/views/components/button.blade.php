<button {{ $attributes->merge(['type' => 'submit', 'class' => 'fx-btn fx-btn--primary']) }}>
    {{ $slot }}
</button>
