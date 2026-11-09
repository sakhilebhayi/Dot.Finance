@extends('errors._ecosystem-layout', ['code' => 404])

@section('title', "We couldn't find that page")
@section('message', "The page you're looking for may have moved, been renamed, or never existed. Double-check the link, or head somewhere we know works.")

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
