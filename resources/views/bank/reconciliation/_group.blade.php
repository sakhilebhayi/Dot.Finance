@php
    $key = $group['key'];
    $cur = $session->currency;
    $shownLines = array_slice($group['lines'], 0, $shown);
    $bulk = in_array($key, ['rule', 'bank_charge', 'interest', 'money_in', 'money_out'], true);
    $mode = ['rule' => 'rule', 'bank_charge' => 'bank_charge', 'interest' => 'interest'][$key] ?? 'entry';
    $type = $key === 'money_in' ? 'income' : ($key === 'money_out' ? 'expense' : null);
    $preticked = in_array($key, ['rule', 'bank_charge', 'interest'], true);
    $formId = 'bank-group-'.$key;
@endphp
<x-fx.panel :id="'bank-group-panel-'.$key" :title="$group['title']" :meta="$group['count'].' '.($group['count'] === 1 ? 'line' : 'lines').', '.\App\Support\Money::format($group['total_cents'], $cur, true)" flush class="r-stack">
    <p class="fx-panel__why">{{ $group['why'] }}</p>

    @if ($bulk && $canWork)
    <form method="POST" action="{{ route('bank.reconciliation.entries', $session) }}" id="{{ $formId }}">
        @csrf
        <input type="hidden" name="mode" value="{{ $mode }}">
    @endif

    <x-fx.table :caption="$group['title']">
        <thead>
            <tr>
                @if ($bulk && $canWork)<th scope="col"><span class="sr-only">Include</span></th>@endif
                <th scope="col">Date</th>
                <th scope="col">On the statement</th>
                <th scope="col">Why it is here</th>
                <th scope="col" class="is-num">Amount</th>
                @unless ($bulk)<th scope="col"><span class="sr-only">Action</span></th>@endunless
            </tr>
        </thead>
        <tbody>
        @foreach ($shownLines as $item)
            @php $line = $item['line']; @endphp
            <tr>
                @if ($bulk && $canWork)
                <td data-label="Include"><x-fx.check name="line_ids[]" :id="$formId.'-line-'.$line->id" :value="$line->id" :checked="$preticked"><span class="sr-only">{{ $line->description }}</span></x-fx.check></td>
                @endif
                <td data-label="Date"><span class="fx-date">{{ substr((string) $line->posted_on, 0, 10) }}</span></td>
                <td data-label="On the statement" class="is-lead"><span class="fx-cell-main">{{ $line->description }}</span>@if ($line->reference)<div class="fx-cell-sub">{{ $line->reference }}</div>@endif</td>
                <td data-label="Why it is here">
                    @if ($item['hint'])<span class="fx-cell-sub">{{ $item['hint'] }}</span>@endif
                </td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="(int) $line->amount_cents" :currency="$cur" :signed="true" /></td>
                @unless ($bulk)
                <td class="is-actions">
                    @if ($canWork)
                    @if ($key === 'transfer')
                    <form method="POST" action="{{ route('bank.reconciliation.transfer', $session) }}">
                        @csrf
                        <input type="hidden" name="line_id" value="{{ $line->id }}">
                        <input type="hidden" name="other_account_id" value="{{ $item['partner']['account_id'] }}">
                        <input type="hidden" name="other_line_id" value="{{ $item['partner']['line_id'] }}">
                        <x-fx.button size="sm">Record transfer with {{ $item['partner']['account_name'] }}</x-fx.button>
                    </form>
                    @elseif ($key === 'documents')
                    @foreach ($item['documents'] as $doc)
                    <form method="POST" action="{{ route('bank.reconciliation.settle', $session) }}">
                        @csrf
                        <input type="hidden" name="line_id" value="{{ $line->id }}">
                        <input type="hidden" name="document_type" value="{{ $doc->type }}">
                        <input type="hidden" name="document_id" value="{{ $doc->id }}">
                        <x-fx.button size="sm">Settle {{ $doc->number }} ({{ \App\Support\Money::format($doc->openCents, $doc->currency) }} open)</x-fx.button>
                    </form>
                    @endforeach
                    @elseif ($key === 'close_amount')
                    <form method="POST" action="{{ route('bank.reconciliation.difference', $session) }}" class="fx-cluster">
                        @csrf
                        <input type="hidden" name="line_id" value="{{ $line->id }}">
                        <input type="hidden" name="entry_id" value="{{ $item['candidate']['id'] }}">
                        <x-fx.input name="reason" :id="'bank-diff-reason-'.$line->id" label="What is the difference?" placeholder="Bank fee, short payment..." required :bind="false" />
                        <x-fx.button size="sm">Match and record the difference</x-fx.button>
                    </form>
                    @elseif ($key === 'timing')
                    <form method="POST" action="{{ route('bank.reconciliation.match', $session) }}">
                        @csrf
                        <input type="hidden" name="line_ids[]" value="{{ $line->id }}">
                        <input type="hidden" name="entry_ids[]" value="{{ $item['candidate']['id'] }}">
                        <x-fx.button size="sm">Match to "{{ \Illuminate\Support\Str::limit($item['candidate']['description'], 24) }}"</x-fx.button>
                    </form>
                    @elseif ($key === 'possible_duplicate')
                    <a class="fx-link" href="{{ route('bank.statements.show', $line->bank_statement_id) }}">Open the statement</a>
                    @endif
                    @endif
                </td>
                @endunless
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>

    @if (count($group['lines']) > $shown)
    <p class="fx-hint fx-panel__why">Showing {{ $shown }} of {{ $group['count'] }}. Match or create these, then the rest appear.</p>
    @endif

    @if ($bulk && $canWork)
        <div class="fx-panel__foot">
            <div class="fx-cluster">
                @if ($key === 'rule')
                <p class="fx-hint">Each line uses its own rule's category. You see the rule named beside the line before anything is created.</p>
                @elseif ($mode === 'entry')
                <x-fx.select name="category_id" :id="$formId.'-category'" label="Category for the ticked lines" optional>
                    <option value="">No category</option>
                    @foreach ($categories->where('type', $type) as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </x-fx.select>
                @endif
                <x-fx.button variant="primary">{{ ['rule' => 'Create the ticked entries', 'bank_charge' => 'Record the ticked as bank charges', 'interest' => 'Record the ticked as interest'][$key] ?? 'Create the ticked entries' }}</x-fx.button>
            </div>
        </div>
    </form>
    @endif
</x-fx.panel>
