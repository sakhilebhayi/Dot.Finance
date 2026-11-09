@extends('errors._ecosystem-layout', ['code' => 500])

@section('title', 'Something went wrong on our end')
@section('message', "That's on us, not you — an unexpected error interrupted this page. Try again in a moment; if it keeps happening, let us know and we'll take a look.")

@section('actions')
    <a href="/" class="fx-btn fx-btn--primary">Go home</a>
    @if (Route::has('contact'))
    <a href="{{ route('contact') }}" class="fx-btn">Contact support</a>
    @endif
@endsection
