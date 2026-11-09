@extends('errors._ecosystem-layout', ['code' => 419])

@section('title', 'Your session timed out')
@section('message', 'This form expires after a while of inactivity. Nothing was lost: go back and submit it again.')

@section('actions')
    <a href="{{ url()->previous() === url()->current() ? '/' : url()->previous() }}" class="fx-btn fx-btn--primary">Go back and retry</a>
    @include('errors._actions', ['code' => 419])
@endsection
