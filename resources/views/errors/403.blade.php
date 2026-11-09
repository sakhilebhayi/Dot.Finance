@extends('errors._ecosystem-layout', ['code' => 403])

@section('title', "You don't have access to this")
@section('message', "Either this belongs to a different team, needs a role you don't currently have, or requires signing in first. If you think this is wrong, check with whoever owns this workspace.")

@section('actions')
    <a href="/" class="fx-btn fx-btn--primary">Go home</a>
    @auth
    <a href="{{ route('dashboard') }}" class="fx-btn">Go to dashboard</a>
    @else
        @if (Route::has('login'))
    <a href="{{ route('login') }}" class="fx-btn">Sign in</a>
        @endif
    @endauth
@endsection
