<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-6">
            <p class="font-mono text-[11px] tracking-[0.18em] uppercase text-[var(--ember)] mb-2">One more step</p>
            <h1 class="font-display font-semibold text-2xl text-[var(--ink)] mb-3">Verify your email</h1>
            <p class="text-sm text-[var(--ink-soft)] leading-relaxed">
                {{ __('Before continuing, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
            </p>
        </div>

        @if (session('status') == 'verification-link-sent')
            <div class="mb-4 font-medium text-sm text-green-700">
                {{ __('A new verification link has been sent to the email address you provided in your profile settings.') }}
            </div>
        @endif

        <div class="mt-4 flex items-center justify-between">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf

                <div>
                    <x-button type="submit">
                        {{ __('Resend Verification Email') }}
                    </x-button>
                </div>
            </form>

            <div>
                <a
                    href="{{ route('profile.show') }}"
                    class="underline text-sm text-[var(--ink-soft)] hover:text-[var(--ink)] rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#e8af39]"
                >
                    {{ __('Edit Profile') }}</a>

                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf

                    <button type="submit" class="underline text-sm text-[var(--ink-soft)] hover:text-[var(--ink)] rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#e8af39] ms-2">
                        {{ __('Log Out') }}
                    </button>
                </form>
            </div>
        </div>
    </x-authentication-card>
</x-guest-layout>
