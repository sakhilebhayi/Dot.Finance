@php
    $answer = $message->answer ?? [];
    $basis = \App\Services\Intelligence\Basis::from($message->basis);
    $none = in_array($message->status, ['no_data', 'unrecognised', 'refused'], true);
@endphp
<article class="intel-exchange" id="answer-{{ $message->id }}" aria-label="Question and answer">
    <div class="intel-exchange__q">
        <q>{{ $message->question }}</q>
        <span>{{ $message->created_at->format('j M, H:i') }}</span>
    </div>

    <p @class(['intel-sentence', 'intel-sentence--none' => $none])>{{ $message->answer_text }}</p>

    <div class="fx-cluster">
        @if ($none)
            <x-fx.pill kind="plain" icon="info">{{ $message->status === 'refused' ? 'Not allowed' : ($message->status === 'unrecognised' ? 'Not understood' : 'No information') }}</x-fx.pill>
        @else
            <x-fx.pill kind="plain" icon="{{ $basis === \App\Services\Intelligence\Basis::Calculated ? 'scale' : 'info' }}" title="{{ $basis->explanation() }}">{{ $basis->label() }}</x-fx.pill>
        @endif
        @if ($message->model_rejected)
            <span class="fx-hint">An AI rewording was discarded because it did not match the figures.</span>
        @endif
    </div>

    @if (! empty($answer['facts']))
    <dl class="intel-facts">
        @foreach ($answer['facts'] as $fact)
        <div @class(['is-variance' => ($fact['tone'] ?? '') === 'variance'])><dt>{{ $fact['label'] }}</dt><dd>{{ $fact['value'] }}</dd></div>
        @endforeach
    </dl>
    @endif

    @if (! empty($answer['rows']))
    <x-fx.table :caption="'The figures behind this answer: '.implode(', ', $answer['columns'] ?? [])" class="intel-table">
        <thead><tr>@foreach ($answer['columns'] as $i => $column)<th scope="col" @class(['is-num' => $i > 0])>{{ $column }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($answer['rows'] as $row)
        <tr>
            @foreach ($row['cells'] as $i => $cell)
            @if ($i === 0)
            <th scope="row" class="is-lead" data-label="{{ $answer['columns'][0] ?? '' }}">@if (! empty($row['href']))<a href="{{ $row['href'] }}">{{ $cell }}</a>@else{{ $cell }}@endif</th>
            @else
            <td class="is-num" data-label="{{ $answer['columns'][$i] ?? '' }}">{{ $cell }}</td>
            @endif
            @endforeach
        </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    @endif

    @if (! empty($answer['notes']))
    <ul class="intel-notes">@foreach ($answer['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul>
    @endif

    @if (! empty($answer['sources']))
    <ul class="intel-chips" aria-label="Sources">
        <li class="fx-hint">Sources</li>
        @foreach ($answer['sources'] as $source)
        <li><a href="{{ $source['href'] }}"><x-fx.icon name="external-link" />{{ $source['label'] }}</a></li>
        @endforeach
    </ul>
    @endif

    @foreach ($message->drafts as $draft)
    <div class="intel-draft">
        <p>
            <strong>Draft, nothing recorded yet.</strong> {{ $draft->summary }}
            @if ($draft->status !== 'pending' || ! $draft->expires_at->isFuture())
                <x-fx.pill kind="plain">{{ $draft->status === 'pending' ? 'expired' : $draft->status }}</x-fx.pill>
            @endif
        </p>
        @if ($draft->isPending())
        <x-fx.button variant="primary" :href="route('intel.assistant.draft', $draft)">Review and confirm</x-fx.button>
        @endif
    </div>
    @endforeach

    <form method="POST" action="{{ route('intel.assistant.destroy', $message) }}">
        @csrf
        @method('DELETE')
        <x-fx.button variant="ghost" size="sm" icon="trash-2">Delete this</x-fx.button>
    </form>
</article>
