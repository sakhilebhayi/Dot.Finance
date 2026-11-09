<x-guest-layout title="Choose a new password">
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <h1>Choose a new password</h1>
        <p class="fx-auth__intro">Use a long, random password you do not use anywhere else.</p>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('password.update') }}" class="fx-form">
            @csrf

            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <x-fx.input name="email" id="email" type="email" label="Email" :value="$request->email" required autofocus autocomplete="username" />
            <x-fx.input name="password" id="password" type="password" label="New password" required autocomplete="new-password" />
            <x-fx.input name="password_confirmation" id="password_confirmation" type="password" label="Confirm new password" required autocomplete="new-password" />

            <div class="fx-auth__links">
                <span></span>
                <x-button>Reset password</x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
