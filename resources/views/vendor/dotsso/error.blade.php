{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout :title="$headline">
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <h1>{{ $headline }}</h1>
        <div class="fx-alert" role="alert"><x-fx.icon name="circle-alert" /><p>{{ $body }}</p></div>

        <div class="fx-auth__links">
            @if ($retry)<a class="fx-btn fx-btn--primary" href="{{ $retryUrl }}">Start again</a>@endif
            <a class="fx-btn" href="{{ $homeUrl }}">Back to Dot.Finance</a>
        </div>
        <p class="fx-auth__intro">Reference: {{ $code }}</p>
    </x-authentication-card>
</x-guest-layout>
