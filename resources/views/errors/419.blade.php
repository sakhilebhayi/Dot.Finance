@extends('errors._ecosystem-layout', ['code' => 419])

@section('title', 'Your session timed out')
@section('message', 'For your security, this form expires after a while of inactivity. Nothing was lost — go back and try submitting again.')

@section('actions')
    <a href="{{ url()->previous() === url()->current() ? '/' : url()->previous() }}" class="fx-btn fx-btn--primary">Go back and retry</a>
    <a href="/" class="fx-btn">Go home</a>
@endsection
