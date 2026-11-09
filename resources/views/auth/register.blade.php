<x-guest-layout title="Create an account">
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <h1>Create an account</h1>
        <p class="fx-auth__intro">Start a ledger of your own.</p>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('register') }}" class="fx-form">
            @csrf

            <x-fx.input name="name" id="name" label="Name" required autofocus autocomplete="name" />
            <x-fx.input name="email" id="email" type="email" label="Email" required autocomplete="username" />
            <x-fx.input name="password" id="password" type="password" label="Password" required autocomplete="new-password" />
            <x-fx.input name="password_confirmation" id="password_confirmation" type="password" label="Confirm password" required autocomplete="new-password" />

            @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
                <x-fx.check name="terms" id="terms" required>
                    {!! __('I agree to the :terms_of_service and :privacy_policy', [
                        'terms_of_service' => '<a target="_blank" rel="noopener" href="'.route('terms.show').'" class="fx-link">'.__('Terms of Service').'</a>',
                        'privacy_policy' => '<a target="_blank" rel="noopener" href="'.route('policy.show').'" class="fx-link">'.__('Privacy Policy').'</a>',
                    ]) !!}
                </x-fx.check>
            @endif

            <div class="fx-auth__links">
                <a class="fx-link" href="{{ route('login') }}">Already registered?</a>
                <x-button>Register</x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
