<x-app-layout :title="$result->title">
<p class="rp-banner rp-noprint">This is the print view. Use your browser's Print command (Ctrl or Cmd + P) and choose "Save as PDF" to keep a copy. <a href="{{ route($routeName, $input) }}">Back to the report</a></p>
<x-fx.page-head :title="$result->title" />
@include('reports._header', ['open' => true])
@include('reports._checks')
@unless ($result->empty)
@include('reports._table')
@else
<p>{{ $result->empty }}</p>
@endunless
@include('reports._foot')
</x-app-layout>
