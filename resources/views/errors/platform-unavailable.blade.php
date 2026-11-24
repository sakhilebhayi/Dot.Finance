{{--
    Shown with a 503 when InfoDot has switched Dot.Finance off (maintenance or unavailable, R-61). It says so plainly and reads nothing of the
    books: every ledger, entry and attachment is kept exactly as it is. Report and attachment downloads still work. Not a purchase or permission problem.
--}}
@extends('errors._ecosystem-layout', ['code' => 503])

@section('title', 'Dot.Finance is not available right now')
@section('message', ($message ?? 'Dot.Finance is temporarily unavailable.').' Your books are kept exactly as they are. Nothing has been changed or lost.')

@section('actions')
    @include('errors._actions', ['code' => 503])
@endsection
