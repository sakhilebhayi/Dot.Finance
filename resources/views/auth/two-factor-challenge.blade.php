<x-guest-layout title="Two-factor sign-in">
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div x-data="{ recovery: false }">
            <h1>Verify it is you</h1>
            <p class="fx-auth__intro" x-show="! recovery">{{ __('Please confirm access to your account by entering the authentication code provided by your authenticator application.') }}</p>
            <p class="fx-auth__intro" x-cloak x-show="recovery">{{ __('Please confirm access to your account by entering one of your emergency recovery codes.') }}</p>

            <x-validation-errors class="mb-4" />

            <form method="POST" action="{{ route('two-factor.login') }}" class="fx-form">
                @csrf

                <div x-show="! recovery">
                    <x-fx.input name="code" id="code" type="text" label="Authentication code" inputmode="numeric" autofocus x-ref="code" autocomplete="one-time-code" figure />
                </div>

                <div x-cloak x-show="recovery">
                    <x-fx.input name="recovery_code" id="recovery_code" type="text" label="Recovery code" x-ref="recovery_code" autocomplete="one-time-code" figure />
                </div>

                <div class="fx-auth__links">
                    <button type="button" class="fx-linkbtn" x-show="! recovery" x-on:click="recovery = true; $nextTick(() => { $refs.recovery_code.focus() })">Use a recovery code</button>
                    <button type="button" class="fx-linkbtn" x-cloak x-show="recovery" x-on:click="recovery = false; $nextTick(() => { $refs.code.focus() })">Use an authentication code</button>
                    <x-button>Log in</x-button>
                </div>
            </form>
        </div>
    </x-authentication-card>
</x-guest-layout>
