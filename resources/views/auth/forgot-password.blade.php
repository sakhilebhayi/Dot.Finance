<x-guest-layout title="Reset your password">
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <h1>Forgot your password?</h1>
        <p class="fx-auth__intro">{{ __('No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}</p>

        @session('status')
            <div class="fx-alert fx-alert--ok" role="status"><x-fx.icon name="check" class="fx-tick-draw" /><p>{{ $value }}</p></div>
        @endsession

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('password.email') }}" class="fx-form">
            @csrf

            <x-fx.input name="email" id="email" type="email" label="Email" required autofocus autocomplete="username" />

            <div class="fx-auth__links">
                <a class="fx-link" href="{{ route('login') }}">Back to sign in</a>
                <x-button>Email the reset link</x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
