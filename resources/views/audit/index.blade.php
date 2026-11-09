<x-app-layout title="Audit log">
@php
    $fieldLabels = [
        'amount_cents' => 'Amount', 'opening_balance_cents' => 'Opening balance', 'opening_balance_date' => 'Opening date',
        'account_id' => 'Account', 'category_id' => 'Category', 'recurring_rule_id' => 'Recurring rule',
        'transfer_direction' => 'Transfer direction', 'transfer_group' => 'Transfer', 'recurrence_type' => 'Repeats',
        'recurrence_interval' => 'Every', 'next_occurrence_date' => 'Next occurrence', 'end_date' => 'Ends', 'start_date' => 'Starts',
        'paused_at' => 'Paused', 'ended_at' => 'Ended',
    ];
@endphp

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
                    @php $changes = $entry->changes ?? []; $isImport = $entry->subject_type === 'import'; @endphp
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
                                <span class="fx-fig">{{ $changes['imported'] ?? 0 }}</span> imported,
                                <span class="fx-fig">{{ $changes['skipped_duplicates'] ?? 0 }}</span> already imported,
                                <span class="fx-fig">{{ $changes['skipped_invalid'] ?? 0 }}</span> unreadable,
                                <span class="fx-fig">{{ $changes['skipped_by_user'] ?? 0 }}</span> skipped by you
                                of <span class="fx-fig">{{ $changes['rows_total'] ?? 0 }}</span> rows.
                                Income <x-money :cents="$changes['income_cents'] ?? 0" :currency="$changes['currency'] ?? null" />,
                                spending <x-money :cents="$changes['expense_cents'] ?? 0" :currency="$changes['currency'] ?? null" />.
                                <div class="fx-cell-sub">dates read as {{ $changes['date_format'] ?? '?' }}, amounts as {{ $changes['amount_locale'] ?? '?' }}</div>
                            @else
                                @forelse($changes as $field => $change)
                                <div>
                                    <span class="fx-cell-main">{{ $fieldLabels[$field] ?? ucfirst(str_replace('_', ' ', $field)) }}</span>
                                    @if(isset($change['changed']))
                                        changed
                                    @else
                                        @php
                                            $fmt = function ($v) use ($field, $entry, $names) {
                                                if ($v === null) return '—';
                                                if (isset($names[$field])) return $names[$field][(int) $v] ?? '#'.$v;
                                                if (str_ends_with($field, '_cents')) return \App\Support\Money::format((int) $v, $entry->currency);
                                                return is_scalar($v) ? (string) $v : json_encode($v);
                                            };
                                        @endphp
                                        @if(array_key_exists('before', $change) && array_key_exists('after', $change))
                                            <span class="fx-fig">{{ $fmt($change['before']) }}</span> &rarr; <span class="fx-fig">{{ $fmt($change['after']) }}</span>
                                        @elseif(array_key_exists('after', $change))
                                            <span class="fx-fig">{{ $fmt($change['after']) }}</span>
                                        @else
                                            was <span class="fx-fig">{{ $fmt($change['before'] ?? null) }}</span>
                                        @endif
                                    @endif
                                </div>
                                @empty
                                    <span class="fx-hint">&mdash;</span>
                                @endforelse
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
