<x-guest-layout title="Sign in">
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <h1>Sign in</h1>
        <p class="fx-auth__intro">Use your InfoDot account. Your ledger is one click away.</p>

        <x-validation-errors class="mb-4" />

        @session('status')
            <div class="fx-alert fx-alert--ok" role="status"><x-fx.icon name="check" class="fx-tick-draw" /><p>{{ $value }}</p></div>
        @endsession

        <form method="POST" action="{{ route('login') }}" class="fx-form">
            @csrf

            <x-fx.input name="email" id="email" type="email" label="Email" required autofocus autocomplete="username" />
            <x-fx.input name="password" id="password" type="password" label="Password" required autocomplete="current-password" />

            <x-fx.check name="remember" id="remember_me" :checked="false">Keep me signed in on this device</x-fx.check>

            <div class="fx-auth__links">
                @if (Route::has('password.request'))
                    <a class="fx-link" href="{{ route('password.request') }}">Forgot your password?</a>
                @endif
                <x-button>Log in</x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
