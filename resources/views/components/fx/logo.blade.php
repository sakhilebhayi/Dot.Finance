{{-- The Dot.Finance logo, the original files untouched (public/images/logo.png on paper, logo-light.png at night). Only
     its size and place are set by the caller's class; the file is never cropped, recoloured or redrawn. The theme
     switch is the same one the rest of the app uses (see .fx-logo in pages.css). Both files are 1181 x 886. --}}
<span {{ $attributes->class(['fx-logo']) }}>
    <img class="fx-logo__day" src="{{ asset('images/logo.png') }}" alt="Dot.Finance" width="1181" height="886" decoding="async">
    <img class="fx-logo__night" src="{{ asset('images/logo-light.png') }}" alt="Dot.Finance" width="1181" height="886" decoding="async">
</span>
