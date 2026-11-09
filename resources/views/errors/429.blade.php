@extends('errors._ecosystem-layout', ['code' => 429])

@section('title', 'Slow down a little')
@section('message', "Too many requests came in a short time. This protects everyone's access. Wait a minute and try again.")

@section('actions')
    @include('errors._actions', ['code' => 429])
@endsection
