@extends('errors._ecosystem-layout', ['code' => 503])

@section('title', "We'll be right back")
@section('message', "This platform is briefly down for scheduled maintenance — we're making some improvements behind the scenes. Check back shortly; nothing you were working on has been lost.")

@section('actions')
    <a href="/" class="fx-btn fx-btn--primary">Try again</a>
@endsection
