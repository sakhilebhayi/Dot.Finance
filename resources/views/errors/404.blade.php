@extends('errors._ecosystem-layout', ['code' => 404])

@section('title', "We couldn't find that page")
@section('message', "The page may have moved or the link may be wrong. Nothing was lost.")

@section('actions')
    @include('errors._actions', ['code' => 404])
@endsection
