<x-app-layout title="Assistant">

<x-fx.page-head title="Assistant">
    <x-slot name="sub">Ask about {{ $ledger->isCompany() ? $ledger->label() : 'your money' }} in plain words. Answers come only from what is recorded here, with the entries behind them. It cannot add, change or approve anything: when it drafts something, you review and confirm it.</x-slot>
</x-fx.page-head>

<x-fx.flash />
<x-fx.errors />

<x-fx.panel title="Ask a question" id="ask">
    <form method="POST" action="{{ route('intel.assistant.ask') }}" class="intel-ask">
        @csrf
        <div class="intel-ask__row">
            <x-fx.input name="question" label="Your question" maxlength="500" required autocomplete="off" placeholder="How much did I spend this month?" :bind="false" />
            <x-fx.button variant="primary" icon="send">Ask</x-fx.button>
        </div>
        <ul class="intel-suggest" aria-label="Questions you can ask">
            @foreach ($suggestions as $suggestion)
            <li><button type="submit" name="question" value="{{ $suggestion }}">{{ $suggestion }}</button></li>
            @endforeach
        </ul>
    </form>
</x-fx.panel>

<x-fx.panel title="Your questions in this ledger" :meta="$messages->isEmpty() ? null : $messages->count().' kept, newest first. Only you can see them.'" id="history">
    @forelse ($messages as $message)
        @include('intel.partials.exchange', ['message' => $message])
    @empty
        <x-fx.empty title="No questions yet.">Ask one above. Your questions and the answers are kept here for you, and you can delete them.</x-fx.empty>
    @endforelse

    @if ($messages->isNotEmpty())
    <x-slot name="foot">
        <form method="POST" action="{{ route('intel.assistant.clear') }}" data-fx-confirm="Delete all your questions and answers in this ledger?" data-fx-confirm-label="Delete history">
            @csrf
            @method('DELETE')
            <x-fx.button variant="ghost" icon="trash-2">Delete my history</x-fx.button>
        </form>
        @if ($modelOn)
        <p class="fx-hint">AI wording is on: the computed answer may be reworded by a language model, and any wording with a number that is not in the calculation is discarded.</p>
        @endif
    </x-slot>
    @endif
</x-fx.panel>

</x-app-layout>
