<x-app-layout title="Journal entry {{ $entry->reference() }}">
@php
    $hasForeign = $entry->lines->contains(fn ($l) => $l->txn_currency);
    $sourceLinked = $source['route'] && ($source['parameter'] !== null || $source['route'] === 'transactions.index' || $source['route'] === 'accounting.periods.index');
@endphp

<x-fx.page-head title="Journal entry {{ $entry->reference() }}">
    <x-slot name="sub">A posted entry is never changed. If it is wrong, a reversal mirrors it and both stay in the books.</x-slot>
    <x-slot name="actions"><x-fx.button :href="route('accounting.journals.index')">All journals</x-fx.button></x-slot>
</x-fx.page-head>

@include('accounting._nav')
@include('accounting._messages')

<article class="fx-voucher" aria-labelledby="voucher-title">
    <header class="fx-voucher__head">
        <div>
            <p class="fx-voucher__kind" id="voucher-title">Journal voucher</p>
            <p class="fx-voucher__ref"><span class="fx-figure">{{ $entry->reference() }}</span></p>
        </div>
        <div class="fx-voucher__state">
            @if ($entry->isReversed())<x-fx.pill icon="history">Reversed</x-fx.pill>
            @elseif ($entry->isReversal())<x-fx.pill icon="history">Reversal</x-fx.pill>
            @else<x-fx.pill kind="ticked">Posted</x-fx.pill>@endif
        </div>
    </header>

    <dl class="fx-kv fx-voucher__meta">
        <div><dt>Date</dt><dd><span class="fx-figure">{{ $entry->entry_date->toDateString() }}</span> <span class="fx-sub">(<x-fx.gloss term="accounting_period">period</x-fx.gloss> {{ $entry->period?->label() }})</span></dd></div>
        <div><dt>Memo</dt><dd>{{ $entry->memo }}</dd></div>
        <div>
            <dt>Source</dt>
            <dd>@if ($sourceLinked)<a href="{{ route($source['route'], $source['parameter'] !== null ? [$source['parameter']] : []) }}" class="fx-link">{{ $source['label'] }}</a>@else{{ $source['label'] }}@endif{{ ', version '.$entry->source_version }}</dd>
        </div>
        <div><dt>Posted</dt><dd>{{ $entry->posted_at->toDayDateTimeString() }}</dd></div>
        @if ($entry->reverses)<div><dt>Reverses</dt><dd><a href="{{ route('accounting.journals.show', $entry->reverses) }}" class="fx-link"><span class="fx-figure">{{ $entry->reverses->reference() }}</span></a></dd></div>@endif
        @if ($entry->reversedBy)<div><dt>Reversed by</dt><dd><a href="{{ route('accounting.journals.show', $entry->reversedBy) }}" class="fx-link"><span class="fx-figure">{{ $entry->reversedBy->reference() }}</span></a></dd></div>@endif
    </dl>

    <x-fx.table caption="Lines of {{ $entry->reference() }}" keep class="fx-table--statement fx-table--voucher fx-table--stack">
        <thead>
            <tr>
                <th scope="col">Account</th>
                <th scope="col" class="fx-col-desc">Description</th>
                <th scope="col" class="is-num"><x-fx.gloss term="debit">Debit</x-fx.gloss></th>
                <th scope="col" class="is-num"><x-fx.gloss term="credit">Credit</x-fx.gloss></th>
                @if ($hasForeign)<th scope="col" class="is-num fx-col-desc">Original amount</th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach ($entry->lines as $line)
            <tr>
                <th scope="row" class="fx-acct">
                    <a href="{{ route('accounting.general-ledger', ['account' => $line->gl_account_id]) }}">{{ $line->glAccount->label() }}</a>
                </th>
                <td @class(['fx-col-desc', 'is-blank' => ! $line->description && ! $line->cashAccount && ! $line->category])>
                    {{ $line->description }}@if($line->cashAccount) <span class="fx-cell-sub">cash account {{ $line->cashAccount->name }}</span>@endif @if($line->category) <span class="fx-cell-sub">category {{ $line->category->name }}</span>@endif
                </td>
                <td data-label="Debit" @class(['is-num', 'is-blank' => ! $line->debit_cents])>@if ($line->debit_cents)<x-fx.money :cents="$line->debit_cents" />@endif</td>
                <td data-label="Credit" @class(['is-num', 'is-blank' => ! $line->credit_cents])>@if ($line->credit_cents)<x-fx.money :cents="$line->credit_cents" />@endif</td>
                @if ($hasForeign)
                <td @class(['is-num fx-col-desc', 'is-blank' => ! $line->txn_currency])>@if($line->txn_currency)<x-fx.money :cents="$line->txn_amount_cents" :currency="$line->txn_currency" /><div class="fx-cell-sub">at {{ number_format($line->fx_rate_e8 / 100000000, 8) }} (rate implied or supplied, see the source)</div>@endif</td>
                @endif
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th scope="row" colspan="{{ 2 }}">Total ({{ $entry->currency }})</th>
                <td data-label="Debit" class="is-num"><x-fx.money :cents="$entry->total_cents" ticked /></td>
                <td data-label="Credit" class="is-num"><x-fx.money :cents="$entry->total_cents" ticked /></td>
                @if ($hasForeign)<td class="fx-col-desc"></td>@endif
            </tr>
        </tfoot>
    </x-fx.table>
    <p class="fx-voucher__note"><x-fx.icon name="check" /> Debits equal credits. The entry cannot be saved any other way.</p>
</article>

@if ($canPost && $reversible)
<x-fx.panel title="Reverse this entry" meta="Creates a new entry that mirrors this one. Both stay in the books.">
    <form method="POST" action="{{ route('accounting.journals.reverse', $entry) }}" class="fx-form" data-fx-confirm="This posts a new entry that mirrors {{ $entry->reference() }}. Both stay in the books and neither can be deleted." data-fx-confirm-label="Reverse the entry">
        @csrf
        <x-fx.input name="reason" id="reason" label="Reason for reversing" required maxlength="300" hint="A posted entry is never changed. A reversal is a new entry that mirrors it; both stay in the books." />
        <div class="fx-formactions"><x-fx.button variant="danger" icon="history">Reverse this entry</x-fx.button></div>
    </form>
</x-fx.panel>
@elseif ($entry->source_type !== 'manual')
<div class="fx-notice" role="note">
    <x-fx.icon name="info" />
    <p>This entry follows its source. To correct it, change or delete the {{ str_replace('_', ' ', $entry->source_type) }} and the journal posts the correction for you.</p>
</div>
@endif
</x-app-layout>
