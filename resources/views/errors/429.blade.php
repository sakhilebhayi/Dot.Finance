@extends('errors._ecosystem-layout', ['code' => 429])

@section('title', 'Slow down a little')
@section('message', "You've made a few too many requests in a short space of time — a safeguard that protects everyone's access. Wait a minute and try again.")

@section('actions')
    <a href="/" class="fx-btn fx-btn--primary">Go home</a>
@endsection
