{{-- R-50: the company does not hold Dot.Finance. Rendered by App\Support\EntitlementRefusal with HTTP 403 inside the
     platform's existing error page, so it carries the same identity. ONE primary action (the link to InfoDot); the
     ledger switches are ordinary buttons. It shows nothing from the books: no names of accounts, no figures. --}}
@extends('errors._ecosystem-layout', ['code' => 403])

@section('title', $title)
@section('message', $body)

@section('actions')
    <a href="{{ $platformsUrl }}" class="fx-btn fx-btn--primary" data-primary-action>Open Platforms in InfoDot</a>

    @auth
        @foreach ($otherLedgers as $other)
            <form method="POST" action="{{ route('ledger.context.switch') }}">
                @csrf
                <button type="submit" name="context" value="{{ $other->value }}" class="fx-btn" aria-label="Switch to {{ $other->name }}">{{ $other->value === 'personal' ? 'Switch to your personal ledger' : 'Switch to '.$other->name }}</button>
            </form>
        @endforeach
    @endauth
@endsection
