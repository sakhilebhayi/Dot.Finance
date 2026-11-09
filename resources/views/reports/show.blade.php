<x-app-layout :title="$result->title">
<x-fx.page-head :title="$result->title" :sub="$definition->description()">
    <x-slot name="actions">
        <x-fx.button :href="route($routeName, $input + ['print' => 1])" variant="ghost" icon="file-text">Print view</x-fx.button>
    </x-slot>
</x-fx.page-head>

<x-fx.flash />
<x-fx.errors />

@include('reports._toolbar')
@include('reports._header')
@include('reports._checks')

@if ($result->empty)
<x-fx.empty :title="$result->empty">Choose another period above, or record the entries this report reads. Nothing is shown that is not in the books.</x-fx.empty>
@else
<x-fx.panel flush>
    @include('reports._table')
</x-fx.panel>
@endif

@include('reports._foot')
</x-app-layout>
