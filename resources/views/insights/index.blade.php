<x-app-layout title="Weekly summaries">

<x-fx.page-head title="Weekly summaries">
    <x-slot name="sub">{{ $insights->total() }} summar{{ $insights->total() === 1 ? 'y' : 'ies' }}.
        @if ($activeLedger?->isCompany())
        Each one is a rule-based summary: plain arithmetic on the company's ledger, worded by fixed rules, one per currency it holds. It is not AI, and it is not financial advice.
        @else
        Each one is a rule-based summary: plain arithmetic on your own ledger, worded by fixed rules, one per currency you hold. It is not AI, and it is not financial advice.
        @endif
        One is written per week.</x-slot>
</x-fx.page-head>

<x-fx.panel>
    @if ($insights->count() > 0)
    <ul class="fx-list">
        @foreach ($insights as $insight)
        <li class="fx-needs__item @if ($insight->type === 'reserve_runway_warning') is-variance @endif">
            <x-fx.icon :name="$insight->type === 'reserve_runway_warning' ? 'triangle-alert' : 'file-text'" />
            <div>
                <p>{{ $insight->insight }}</p>
                <p class="fx-hint">Rule-based summary, {{ $insight->iso_week ? 'week '.str_replace('-W', ', week ', $insight->iso_week) : $insight->created_at->format('j M Y') }}</p>
            </div>
        </li>
        @endforeach
    </ul>
    <x-slot name="foot">{{ $insights->links() }}</x-slot>
    @else
    <x-fx.empty title="No summaries yet.">No summaries yet. One is written each week once {{ $activeLedger?->isCompany() ? "the company's" : 'your' }} ledger has some spending history.</x-fx.empty>
    @endif
</x-fx.panel>

</x-app-layout>
