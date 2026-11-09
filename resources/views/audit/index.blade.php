<x-app-layout title="Audit log">
<x-fx.page-head title="Audit log">
    <x-slot name="sub">
        @if($activeLedger?->isCompany())
        Everyone in {{ $activeLedger->label() }}: every change anyone made to the company's accounts, transactions, categories, budgets and recurring items, with who made it, newest first. Only an owner or admin can read this. Entries can be read here but never edited or removed.
        @else
        Every change to your accounts, transactions, categories, budgets and recurring items, newest first. Entries can be read here but never edited or removed.
        @endif
        Amounts, dates and categories show before and after; free text such as descriptions and notes is recorded as "changed" without the words.
    </x-slot>
</x-fx.page-head>

<form method="GET" action="{{ route('audit.index') }}" class="fx-panel fx-panel__body fx-form r-stack" role="search" aria-label="Filter the audit log">
    <div class="fx-row fx-row--3">
        <x-fx.select name="subject" id="audit-index-subject" label="Show">
            <option value="">Everything</option>
            @foreach($subjects as $s)
            <option value="{{ $s }}" @selected($subject === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}s</option>
            @endforeach
        </x-fx.select>
        @if($actors->isNotEmpty() || $actor)
        <x-fx.select name="actor" id="audit-index-actor" label="Who">
            <option value="">Everyone</option>
            @foreach($actors as $person)
            <option value="{{ $person->id }}" @selected($actor === $person->id)>{{ $person->name }}</option>
            @endforeach
            <option value="system" @selected($actor === 'system')>Automatic (scheduled)</option>
        </x-fx.select>
        @endif
    </div>
    <div class="fx-formactions">
        <x-fx.button variant="primary">Filter</x-fx.button>
        @if($subject || $actor)<x-fx.button :href="route('audit.index')">Clear</x-fx.button>@endif
    </div>
</form>

<x-fx.panel flush>
    @if($entries->count() > 0)
    <x-fx.table caption="Every recorded change, newest first">
        <thead>
            <tr>
                <th scope="col">When</th>
                <th scope="col">Action</th>
                <th scope="col">Record</th>
                <th scope="col">What changed</th>
            </tr>
        </thead>
        <tbody>
                    @foreach($entries as $entry)
                    @php
                        $changes = is_array($entry->changes) ? $entry->changes : [];
                        $isImport = $entry->subject_type === 'import';
                        $n = fn ($k) => is_numeric($changes[$k] ?? null) ? (int) $changes[$k] : 0;
                        $t = fn ($k) => is_scalar($changes[$k] ?? null) ? (string) $changes[$k] : '?';
                        $cur = is_string($changes['currency'] ?? null) ? $changes['currency'] : null;
                    @endphp
                    <tr>
                        <td data-label="When" class="fx-fig">
                            {{ $entry->created_at->format('Y-m-d H:i') }}
                            <div class="fx-cell-sub">@if($entry->actor === 'system')
                                automatic
                            @elseif($activeLedger?->isCompany())
                                {{ $entry->actorUser?->name ?? 'a former member' }}
                            @else
                                you
                            @endif</div>
                        </td>
                        <td data-label="Action">
                            {{ str_replace('_', ' ', $entry->action) }}
                        </td>
                        <td data-label="Record">
                            <x-fx.pill>{{ str_replace('_', ' ', $entry->subject_type) }}</x-fx.pill>
                            {{ $entry->subject_label }}
                            @if($entry->context)<div class="fx-cell-sub">{{ $entry->context }}</div>@endif
                        </td>
                        <td data-label="What changed">
                            @if($isImport)
                                <span class="fx-fig">{{ $n('imported') }}</span> imported,
                                <span class="fx-fig">{{ $n('skipped_duplicates') }}</span> already imported,
                                <span class="fx-fig">{{ $n('skipped_invalid') }}</span> unreadable,
                                <span class="fx-fig">{{ $n('skipped_by_user') }}</span> skipped by you
                                of <span class="fx-fig">{{ $n('rows_total') }}</span> rows.
                                Income <x-money :cents="$n('income_cents')" :currency="$cur" />,
                                spending <x-money :cents="$n('expense_cents')" :currency="$cur" />.
                                <div class="fx-cell-sub">dates read as {{ $t('date_format') }}, amounts as {{ $t('amount_locale') }}</div>
                            @else
                                @php $view = $views[$entry->id] ?? ['lines' => [], 'working' => [], 'unreadable' => true]; @endphp
                                @if($view['unreadable'])
                                    <span class="fx-hint">This entry could not be shown in full. The record itself is intact.</span>
                                @endif
                                @forelse($view['lines'] as $line)
                                <div>
                                    <span class="fx-cell-main">{{ $line['label'] }}</span>
                                    @if($line['kind'] === 'changed')
                                        changed
                                    @elseif($line['kind'] === 'pair')
                                        <span class="fx-fig">{{ $line['before'] }}</span> &rarr; <span class="fx-fig">{{ $line['after'] }}</span>
                                    @elseif($line['kind'] === 'was')
                                        was <span class="fx-fig">{{ $line['before'] }}</span>
                                    @else
                                        <span class="fx-fig">{{ $line['text'] }}</span>
                                    @endif
                                </div>
                                @empty
                                    @unless($view['unreadable'])<span class="fx-hint">&mdash;</span>@endunless
                                @endforelse
                                @if($view['working'] !== [])
                                <details class="fx-cell-sub"><summary>Show working</summary>
                                    @foreach($view['working'] as $w)<div>{{ $w['label'] }}: <span class="fx-fig">{{ $w['text'] }}</span></div>@endforeach
                                </details>
                                @endif
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
    </x-fx.table>
    <x-slot name="foot">{{ $entries->links() }}</x-slot>
    @else
    <x-fx.empty title="Nothing recorded yet.">Nothing recorded yet. Changes to accounts, transactions, categories, budgets and recurring items appear here as they happen.</x-fx.empty>
    @endif
</x-fx.panel>

</x-app-layout>
