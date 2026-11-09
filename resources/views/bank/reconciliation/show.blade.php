<x-app-layout title="Reconcile {{ $account->name }}">
@php
    $cur = $session->currency;
    $isDraft = $session->isDraft();
    $zero = $isDraft ? ($difference === 0) : true;
@endphp

<x-fx.page-head :title="($isDraft ? 'Reconcile ' : 'Reconciled: ').$account->name" :sub="'Statement to '.$session->statement_date->toDateString().', closing balance '.\App\Support\Money::format($session->statement_closing_cents, $cur)">
    <x-slot name="actions">
        <x-fx.button :href="route('bank.reconciliation.report', $session)" icon="file-text">Reconciliation report</x-fx.button>
    </x-slot>
</x-fx.page-head>

@include('bank._tabs')
<x-fx.flash />
<x-fx.errors />

{{-- The proof: the one question this screen answers is how far the books are from the statement. --}}
<div class="fx-grid fx-grid--lead">
    <x-fx.panel title="Statement against your books" class="r-stack">
        <x-fx.statement class="bk-statement">
            <x-fx.statement-row label="Closing balance on the statement"><x-fx.money :cents="$session->statement_closing_cents" :currency="$cur" /></x-fx.statement-row>
            <x-fx.statement-row label="Cleared balance in your books" kind="subtotal"><x-fx.money :cents="$isDraft ? $cleared : (int) $session->ledger_cleared_cents" :currency="$cur" /></x-fx.statement-row>
            <x-fx.statement-row label="Difference" kind="total">
                @if ($isDraft)<x-fx.money :cents="$difference" :currency="$cur" :signed="true" :lead="true" :ticked="$difference === 0" :variance="$difference !== 0" />
                @else<x-fx.money :cents="0" :currency="$cur" :lead="true" :ticked="true" />@endif
            </x-fx.statement-row>
        </x-fx.statement>

        @if ($isDraft)
        <p class="fx-hint">
            {{ $openCount }} statement {{ $openCount === 1 ? 'line is' : 'lines are' }} still open.
            @if ($outstandingTotals['count'] > 0){{ $outstandingTotals['count'] }} {{ $outstandingTotals['count'] === 1 ? 'entry' : 'entries' }} in your books {{ $outstandingTotals['count'] === 1 ? 'is' : 'are' }} not on the statement yet.@endif
        </p>
        @else
        <p class="fx-hint">Completed {{ $session->completed_at?->toDayDateTimeString() }} by {{ $session->completer->name ?? 'a member' }}. The {{ $matchCount }} {{ $matchCount === 1 ? 'match' : 'matches' }} it holds are locked.</p>
        @endif

        @if ($isDraft && $session->statement_opening_cents !== null && $broughtForward !== null)
            @if ($session->statement_opening_cents === $broughtForward)
            <p class="fx-hint">The opening balance agrees: the statement and your books both start at {{ \App\Support\Money::format($broughtForward, $cur) }}@if ($session->baseline_date), and entries before {{ $session->baseline_date->toDateString() }} are taken as already agreed with the bank, because this is the first reconciliation of the account @endif.</p>
            @else
            <div class="fx-alert" role="status"><x-fx.icon name="circle-alert" /><p>The statement opens at {{ \App\Support\Money::format($session->statement_opening_cents, $cur) }} but your books hold {{ \App\Support\Money::format($broughtForward, $cur) }} before it, {{ \App\Support\Money::format(abs($session->statement_opening_cents - $broughtForward), $cur) }} {{ $session->statement_opening_cents > $broughtForward ? 'less' : 'more' }} than the bank. Something before this statement is missing or wrong in your books, or the account's opening balance needs adjusting. The difference above carries this.</p></div>
            @endif
        @elseif ($isDraft && $session->baseline_date)
        <p class="fx-hint">Entries before {{ $session->baseline_date->toDateString() }} are taken as already agreed with the bank, because this is the first reconciliation of the account. Give the statement's opening balance to check that.</p>
        @endif
    </x-fx.panel>

    <x-fx.panel title="{{ $isDraft ? 'Finish' : 'This reconciliation' }}" class="r-stack">
        @if ($isDraft)
            @if ($zero && $openCount === 0)
            <p>Everything is matched and the difference is zero. Completing locks the {{ $matchCount }} matched {{ $matchCount === 1 ? 'entry' : 'entries' }} so they cannot change under the statement.</p>
            @else
            <p>To finish, the difference must be exactly zero and every line explained.@if (! $zero) It is <x-fx.money :cents="$difference" :currency="$cur" :signed="true" :variance="true" /> now.@endif</p>
            @endif

            @if ($canWork)
            <form method="POST" action="{{ route('bank.reconciliation.complete', $session) }}" class="fx-form r-stack">
                @csrf
                @if (! $zero || $openCount > 0)
                    @if ($canVariance)
                    <details class="bk-fold">
                        <summary><span>Accept a difference with a reason</span><x-fx.icon name="chevron-down" /></summary>
                        <div class="fx-stack">
                            <p class="fx-hint">An accountant can accept what cannot be traced. It is recorded as a real entry in your books, with your reason, and shown on the report as an accepted difference.</p>
                            <x-fx.check name="accept_variance" id="bank-accept-variance">Accept the difference and post it</x-fx.check>
                            <x-fx.textarea name="variance_reason" id="bank-variance-reason" label="Why is it being accepted?" optional rows="2" />
                        </div>
                    </details>
                    @else
                    <p class="fx-hint">Accepting a difference needs an accountant or finance manager.</p>
                    @endif
                @endif
                <x-fx.button variant="primary" icon="check">Complete the reconciliation</x-fx.button>
            </form>
            @endif
        @else
            <x-fx.statement class="bk-statement">
                <x-fx.statement-row label="Entries cleared">{{ $report['counts']['entries'] }}</x-fx.statement-row>
                <x-fx.statement-row label="Statement lines cleared">{{ $report['counts']['lines'] }}</x-fx.statement-row>
                @if ($session->variance_cents !== 0)<x-fx.statement-row label="Difference accepted"><x-fx.money :cents="$session->variance_cents" :currency="$cur" :signed="true" :variance="true" /></x-fx.statement-row>@endif
            </x-fx.statement>
            @if ($session->variance_cents !== 0)<p class="fx-hint">Accepted by {{ $session->variance_by ? \App\Models\User::find($session->variance_by)?->name : 'a member' }}: {{ $session->variance_reason }}</p>@endif

            @if ($canVariance)
            <details class="bk-fold">
                <summary><span>Reopen this reconciliation</span><x-fx.icon name="chevron-down" /></summary>
                <form method="POST" action="{{ route('bank.reconciliation.reopen', $session) }}" class="fx-stack" data-fx-confirm="Reopen this reconciliation? The entries it locked become changeable once un-matched. The reason is kept." data-fx-confirm-label="Reopen">
                    @csrf
                    <p class="fx-hint">Only the newest reconciliation of an account can be reopened. A reason is required and is kept in the history.</p>
                    <x-fx.textarea name="reason" id="bank-reopen-reason" label="Why is it being reopened?" rows="2" />
                    <x-fx.button icon="history">Reopen</x-fx.button>
                </form>
            </details>
            @endif
        @endif
    </x-fx.panel>
</div>

@if ($isDraft)

{{-- Matched for you --}}
@if (count($auto) > 0)
<x-fx.panel title="Matched for you" :meta="count($auto).' '.(count($auto) === 1 ? 'match' : 'matches').' with the same amount, a close date and shared words'" flush class="r-stack">
    <p class="fx-panel__why">Each says why. Accepting records them; you can still un-match any of them until you complete.</p>
    <details class="bk-fold" @if (count($auto) <= 8) open @endif>
        <summary><span>Why these match</span><x-fx.icon name="chevron-down" /></summary>
        <x-fx.table caption="Matches the engine is sure of">
            <thead><tr><th scope="col">On the statement</th><th scope="col">In your books</th><th scope="col">Why</th><th scope="col" class="is-num">Confidence</th></tr></thead>
            <tbody>
            @foreach (array_slice($auto, 0, $shown) as $proposal)
                @include('bank.reconciliation._proposal', ['proposal' => $proposal])
            @endforeach
            </tbody>
        </x-fx.table>
        @if (count($auto) > $shown)<p class="fx-hint fx-panel__why">Showing {{ $shown }} of {{ count($auto) }}. Accepting takes all {{ count($auto) }}.</p>@endif
    </details>
    @if ($canWork)
    <div class="fx-panel__foot">
        <form method="POST" action="{{ route('bank.reconciliation.accept', $session) }}">
            @csrf
            <input type="hidden" name="mode" value="auto">
            <x-fx.button variant="primary" icon="check">Accept all {{ count($auto) }}</x-fx.button>
        </form>
    </div>
    @endif
</x-fx.panel>
@endif

{{-- Needs a look --}}
@if (count($review) > 0)
<x-fx.panel title="Needs a look" :meta="count($review).' to check'" flush class="r-stack">
    <p class="fx-panel__why">Likely, but not certain: the amount fits and something else is thin, or more than one entry could be the one, or several entries add up to a line. Check each, then match.</p>
    <x-fx.table caption="Suggested matches to check">
        <thead><tr><th scope="col">On the statement</th><th scope="col">In your books</th><th scope="col">Why</th><th scope="col" class="is-num">Confidence</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
        <tbody>
        @foreach (array_slice($review, 0, $shown) as $proposal)
            @include('bank.reconciliation._proposal', ['proposal' => $proposal, 'withAction' => true])
        @endforeach
        </tbody>
    </x-fx.table>
    @if (count($review) > $shown)<p class="fx-hint fx-panel__why">Showing {{ $shown }} of {{ count($review) }}.</p>@endif
</x-fx.panel>
@endif

{{-- Exceptions inbox --}}
@forelse ($groups as $group)
    @include('bank.reconciliation._group', ['group' => $group])
@empty
    @if (count($auto) === 0 && count($review) === 0)
    <x-fx.panel title="Exceptions" class="r-stack">
        <x-fx.empty title="Nothing is waiting for a decision.">Every open statement line has been matched. Complete the reconciliation when the difference is zero.</x-fx.empty>
    </x-fx.panel>
    @endif
@endforelse

{{-- Match by hand --}}
@if ($canWork && ($unproposedCount > 0 || count($outstanding) > 0))
<form method="POST" action="{{ route('bank.reconciliation.match', $session) }}" class="r-stack">
    @csrf
    <x-fx.panel title="Match by hand" :meta="'Tick what says the same thing. They must add up to the same amount.'" flush>
        <div class="fx-grid fx-grid--even">
            <div>
                <h3 class="fx-section-title">Statement lines</h3>
                <x-fx.table caption="Open statement lines">
                    <thead><tr><th scope="col"><span class="sr-only">Tick</span></th><th scope="col">Line</th><th scope="col" class="is-num">Amount</th></tr></thead>
                    <tbody>
                    @foreach (collect($lines)->reject(fn ($l) => false)->take($shown) as $line)
                        <tr>
                            <td data-label="Tick"><x-fx.check name="line_ids[]" :id="'bank-hand-line-'.$line->id" :value="$line->id"><span class="sr-only">{{ $line->description }}</span></x-fx.check></td>
                            <td data-label="Line" class="is-lead"><span class="fx-cell-main">{{ $line->description }}</span><div class="fx-cell-sub"><span class="fx-date">{{ substr((string) $line->posted_on, 0, 10) }}</span></div></td>
                            <td data-label="Amount" class="is-num"><x-fx.money :cents="(int) $line->amount_cents" :currency="$cur" :signed="true" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </x-fx.table>
            </div>
            <div>
                <h3 class="fx-section-title">Entries not on the statement yet</h3>
                @if (count($outstanding) === 0)
                <x-fx.empty title="None.">Every entry up to this date is already matched.</x-fx.empty>
                @else
                <x-fx.table caption="Entries in your books not yet on a statement">
                    <thead><tr><th scope="col"><span class="sr-only">Tick</span></th><th scope="col">Entry</th><th scope="col" class="is-num">Amount</th></tr></thead>
                    <tbody>
                    @foreach ($outstanding as $entry)
                        <tr>
                            <td data-label="Tick"><x-fx.check name="entry_ids[]" :id="'bank-hand-entry-'.$entry->id" :value="$entry->id"><span class="sr-only">{{ $entry->description }}</span></x-fx.check></td>
                            <td data-label="Entry" class="is-lead"><span class="fx-cell-main">{{ $entry->description }}</span><div class="fx-cell-sub"><span class="fx-date">{{ $entry->date }}</span></div></td>
                            <td data-label="Amount" class="is-num"><x-fx.money :cents="$entry->signed" :currency="$cur" :signed="true" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </x-fx.table>
                @if ($outstandingTotals['count'] > count($outstanding))<p class="fx-hint">Showing the oldest {{ count($outstanding) }} of {{ $outstandingTotals['count'] }}.</p>@endif
                @endif
            </div>
        </div>
        <x-slot name="foot"><x-fx.button variant="primary" icon="git-compare">Match the ticked</x-fx.button></x-slot>
    </x-fx.panel>
</form>
@endif

@endif {{-- draft --}}

{{-- Matched so far --}}
<x-fx.panel title="{{ $isDraft ? 'Matched so far' : 'What it matched' }}" :meta="$matchCount.' '.($matchCount === 1 ? 'match' : 'matches')" flush class="r-stack">
    @if ($matchRows->isEmpty())
    <x-fx.empty title="Nothing matched yet." />
    @else
    <x-fx.table caption="Matches in this reconciliation, newest first">
        <thead><tr><th scope="col">On the statement</th><th scope="col">In your books</th><th scope="col">How</th>@if ($isDraft && $canWork)<th scope="col"><span class="sr-only">Action</span></th>@endif</tr></thead>
        <tbody>
        @foreach ($matchRows as $row)
            @php $m = $row['match']; @endphp
            <tr>
                <td data-label="On the statement" class="is-lead">
                    @forelse ($row['lines'] as $l)<span class="fx-cell-main">{{ $l->description }}</span><div class="fx-cell-sub"><span class="fx-date">{{ substr((string) $l->posted_on, 0, 10) }}</span>, <x-fx.money :cents="(int) $l->amount_cents" :currency="$cur" :signed="true" :quiet="true" /></div>@empty<span class="fx-cell-sub">No statement line: an accepted difference.</span>@endforelse
                </td>
                <td data-label="In your books">
                    @foreach ($row['entries'] as $e)<span class="fx-cell-main">{{ $e->description }}</span><div class="fx-cell-sub"><span class="fx-date">{{ substr((string) $e->date, 0, 10) }}</span></div>@endforeach
                </td>
                <td data-label="How"><x-fx.pill kind="plain">{{ ['auto' => 'Matched for you', 'manual' => 'By hand', 'rule' => 'By a rule', 'created' => 'Entry created', 'transfer' => 'Transfer', 'variance' => 'Difference accepted'][$m->method] ?? $m->method }}</x-fx.pill><div class="fx-cell-sub">{{ $m->reason }}</div></td>
                @if ($isDraft && $canWork)
                <td class="is-actions">
                    <form method="POST" action="{{ route('bank.reconciliation.unmatch', [$session, $m]) }}" data-fx-confirm="Un-match these? They go back to the open lists. The match stays in the history." data-fx-confirm-label="Un-match">
                        @csrf
                        <button type="submit" class="fx-linkbtn">Un-match<span class="sr-only"> {{ $row['lines']->first()->description ?? 'this' }}</span></button>
                    </form>
                </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    @if ($matchCount > $matchRows->count())<x-slot name="foot">Showing the newest {{ $matchRows->count() }} of {{ $matchCount }}.</x-slot>
    @endif
    @endif
</x-fx.panel>

{{-- History: nothing here is ever edited or removed --}}
<x-fx.panel title="History" meta="Append-only: each step is kept with who did it" flush class="r-stack">
    <x-fx.table caption="Recent steps in this reconciliation, newest first">
        <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What happened</th></tr></thead>
        <tbody>
        @foreach ($events as $event)
            <tr>
                <td data-label="When"><span class="fx-date">{{ $event->created_at?->format('Y-m-d H:i') }}</span></td>
                <td data-label="Who">{{ $event->actor->name ?? 'System' }}</td>
                <td data-label="What happened" class="is-lead">{{ ['started' => 'Started the reconciliation', 'matched' => 'Matched', 'matched_in_bulk' => 'Accepted several matches', 'unmatched' => 'Un-matched', 'adjustment' => 'Added an adjustment', 'completed' => 'Completed', 'reopened' => 'Reopened'][$event->event] ?? $event->event }}@if ($event->event === 'reopened' && ! empty($event->data['reason']))<div class="fx-cell-sub">{{ $event->data['reason'] }}</div>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>

</x-app-layout>
