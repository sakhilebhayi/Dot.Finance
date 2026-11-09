@php $cur = $session->currency; @endphp
<tr>
    <td data-label="On the statement" class="is-lead">
        @foreach ($proposal['line_ids'] as $id)
        @php $line = $lines[$id]; @endphp
        <span class="fx-cell-main">{{ $line->description }}</span>
        <div class="fx-cell-sub"><span class="fx-date">{{ substr((string) $line->posted_on, 0, 10) }}</span>, <x-fx.money :cents="(int) $line->amount_cents" :currency="$cur" :signed="true" :quiet="true" /></div>
        @endforeach
    </td>
    <td data-label="In your books">
        @foreach ($proposal['entry_ids'] as $id)
        @php $entry = $entries[$id]; @endphp
        <span class="fx-cell-main">{{ $entry->description }}</span>
        <div class="fx-cell-sub"><span class="fx-date">{{ substr((string) $entry->date, 0, 10) }}</span>, <x-fx.money :cents="(int) $entry->signed" :currency="$cur" :signed="true" :quiet="true" /></div>
        @endforeach
    </td>
    <td data-label="Why"><span class="fx-cell-sub">{{ $proposal['reason'] }}</span></td>
    <td data-label="Confidence" class="is-num"><x-fx.pill :kind="$proposal['confidence'] >= \App\Services\Bank\MatchEngine::AUTO ? 'ticked' : 'plain'" :icon="$proposal['confidence'] >= \App\Services\Bank\MatchEngine::AUTO ? 'check' : null">{{ $proposal['confidence'] }} of 100</x-fx.pill></td>
    @if ($withAction ?? false)
    <td class="is-actions">
        @if ($canWork)
        <form method="POST" action="{{ route('bank.reconciliation.accept', $session) }}">
            @csrf
            <input type="hidden" name="mode" value="one">
            <input type="hidden" name="line_id" value="{{ $proposal['line_ids'][0] }}">
            <x-fx.button size="sm">{{ $proposal['shape'] === 'one_to_one' ? 'Match' : 'Match together' }}</x-fx.button>
        </form>
        @endif
    </td>
    @endif
</tr>
