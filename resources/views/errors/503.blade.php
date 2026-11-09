@extends('errors._ecosystem-layout', ['code' => 503])

@section('title', "We'll be right back")
@section('message', "Dot.Finance is down for a short time for maintenance. Nothing you were working on has been lost. Try again shortly.")

@section('actions')
    @include('errors._actions', ['code' => 503])
@endsection
