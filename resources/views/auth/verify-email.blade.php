<x-guest-layout title="Verify your email">
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <h1>Verify your email</h1>
        <p class="fx-auth__intro">{{ __('Before continuing, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}</p>

        @if (session('status') == 'verification-link-sent')
            <div class="fx-alert fx-alert--ok" role="status"><x-fx.icon name="check" class="fx-tick-draw" /><p>{{ __('A new verification link has been sent to the email address you provided in your profile settings.') }}</p></div>
        @endif

        <div class="fx-auth__links">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-button type="submit">Resend the verification email</x-button>
            </form>

            <div class="fx-cluster">
                <a href="{{ route('profile.show') }}" class="fx-link">Edit profile</a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="fx-linkbtn">Log out</button>
                </form>
            </div>
        </div>
    </x-authentication-card>
</x-guest-layout>
