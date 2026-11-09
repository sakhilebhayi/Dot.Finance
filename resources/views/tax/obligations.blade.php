<x-app-layout title="What applies">
    <x-fx.page-head title="What applies to this business" sub="The working behind each answer. Every fact says how we know it.">
        <x-slot name="actions"><x-fx.button :href="route('tax.profile.edit')">Change the profile</x-fx.button></x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash />

    @foreach ($obligations as $o)
    <x-fx.panel :title="$o->label" :meta="$o->summary">
        <x-slot name="head"><x-fx.pill :kind="in_array($o->status, ['review', 'not_configured'], true) ? 'variance' : 'plain'">{{ str_replace('_', ' ', ucfirst($o->status)) }}</x-fx.pill></x-slot>
        <div class="fx-stack">
            @foreach ($o->reasoning as $line)<p>{{ $line }}</p>@endforeach
            @if (count($o->facts))
            <x-fx.statement>
                @foreach ($o->facts as $f)
                    <x-fx.statement-row :label="$f['label']">{{ $f['value'] }} <x-tax.basis :basis="$f['basis']" /></x-fx.statement-row>
                @endforeach
            </x-fx.statement>
            @endif
            <p class="fx-hint">Confidence in the rules behind this: {{ $o->confidence }}.@if ($o->ruleVersions) Rules: {{ implode('; ', $o->ruleVersions) }}.@endif</p>
        </div>
    </x-fx.panel>
    @endforeach
    @include('tax._footer')
</x-app-layout>
