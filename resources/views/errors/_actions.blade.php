@php
    // Where an error page sends a person (R-44 polish, D-1715). A signed-in person goes back to the Command centre, never to a list of
    // other products; a signed-out person goes to the sign-in page. "Report this problem" opens a message already naming the page and
    // the error, to the support address when one is configured and otherwise to InfoDot.
    $fxSignedIn = false;
    try {
        $fxSignedIn = auth()->check();
    } catch (\Throwable) {
    }
    $fxSupport = config('support.email');
    $fxReport = $fxSupport
        ? 'mailto:'.$fxSupport.'?subject='.rawurlencode('Dot.Finance problem '.($code ?? '').' on '.request()->path()).'&body='.rawurlencode("What I was doing:\n\n\nPage: ".request()->fullUrl()."\nError: ".($code ?? ''))
        : (string) config('ecosystem.platforms.infodot.url', '/');
@endphp
@if ($fxSignedIn)
<a href="{{ route('dashboard') }}" class="fx-btn fx-btn--primary">Back to the Command centre</a>
@else
@if (Route::has('login'))<a href="{{ route('login') }}" class="fx-btn fx-btn--primary">Sign in</a>@endif
<a href="/" class="fx-btn">Go to the home page</a>
@endif
<a href="{{ $fxReport }}" class="fx-btn">Report this problem</a>
