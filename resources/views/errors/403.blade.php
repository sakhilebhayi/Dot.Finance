@extends('errors._ecosystem-layout', ['code' => 403])

@section('title', "You don't have access to this")
@section('message', "It may belong to another ledger, need a role you don't have, or need you to sign in first. If you think this is wrong, ask whoever owns this ledger.")

@section('actions')
    @include('errors._actions', ['code' => 403])
@endsection
