@extends('errors._ecosystem-layout', ['code' => 500])

@section('title', 'Something went wrong on our end')
@section('message', "That is our fault, not yours. Nothing you entered was changed by this page. Try again in a moment, and if it keeps happening, report it.")

@section('actions')
    @include('errors._actions', ['code' => 500])
@endsection
