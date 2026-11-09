<x-guest-layout title="Confirm your password">
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <h1>Confirm your password</h1>
        <p class="fx-auth__intro">{{ __('This is a secure area of the application. Please confirm your password before continuing.') }}</p>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('password.confirm') }}" class="fx-form">
            @csrf

            <x-fx.input name="password" id="password" type="password" label="Password" required autocomplete="current-password" autofocus />

            <div class="fx-auth__links">
                <span></span>
                <x-button>Confirm</x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
