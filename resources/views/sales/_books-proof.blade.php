{{-- One line that says whether receivables agree with the books; when they do not, the gap is shown beside it, never behind a click. --}}
@php $cur = \App\Models\Accounting\LedgerSetting::query()->first()?->base_currency; @endphp
@if ($summary['reconciles'])
    <x-fx.pill kind="ticked">Agrees with the books</x-fx.pill>
@else
    <x-fx.pill kind="variance">Books differ by {{ \App\Support\Money::format(abs($summary['difference_cents']), $cur) }}</x-fx.pill>
    <a class="fx-link" href="{{ route('sales.receivables.index') }}#reconciling-items">See what makes up the gap</a>
@endif
@if ($summary['reconciles'])<a class="fx-link" href="{{ route('sales.receivables.index') }}">Receivables</a>@endif
