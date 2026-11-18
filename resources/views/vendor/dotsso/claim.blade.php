{{-- R-54: the claim page, in The Tie-Out. Shown when a ledger here already uses the email address of the InfoDot sign-in.
     Nothing on the account is replaced; the code proves the address. Variables come from the shared package. --}}
<x-guest-layout title="Confirm it is you">
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <h1>Confirm it is you</h1>
        <p class="fx-auth__intro">A ledger here already uses <strong>{{ $maskedEmail }}</strong>. We sent a {{ $codeLength }}-digit code to that address. Enter it to connect the two. Your ledgers, entries and companies stay exactly as they are.</p>

        @if (session('dotsso_status'))
            <div class="fx-alert fx-alert--ok" role="status"><x-fx.icon name="check" class="fx-tick-draw" /><p>{{ session('dotsso_status') }}</p></div>
        @endif

        <form method="POST" action="{{ $verifyUrl }}" class="fx-form" novalidate>
            @csrf
            <x-fx.input name="code" id="claim-code" type="text" label="Code from the email" inputmode="numeric" pattern="[0-9]*"
                autocomplete="one-time-code" maxlength="{{ $codeLength }}" required autofocus :bind="false"
                hint="It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries." />
            <div class="fx-auth__links">
                <x-button>Confirm and continue</x-button>
            </div>
        </form>

        <div class="fx-auth__links">
            <form method="POST" action="{{ $resendUrl }}">@csrf<button type="submit" class="fx-btn fx-btn--ghost">Send a new code</button></form>
            <form method="POST" action="{{ $cancelUrl }}">@csrf<button type="submit" class="fx-btn fx-btn--ghost">Cancel</button></form>
        </div>
    </x-authentication-card>
</x-guest-layout>
