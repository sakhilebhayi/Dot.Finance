<x-app-layout title="VAT review queue">
    <x-fx.page-head title="VAT review queue" :sub="'Postings to look at before the return for '.$from->format('j M Y').' to '.$to->format('j M Y').' is prepared.'">
        <x-slot name="actions">
            <form method="GET" action="{{ route('tax.vat.review') }}" class="fx-cluster">
                <label class="sr-only" for="from">From</label><input id="from" type="date" name="from" class="fx-input" value="{{ $from->toDateString() }}">
                <label class="sr-only" for="to">To</label><input id="to" type="date" name="to" class="fx-input" value="{{ $to->toDateString() }}">
                <x-fx.button>Show</x-fx.button>
            </form>
        </x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.flash /><x-fx.errors />

    @if (count($issues) === 0)
        <x-fx.empty title="Nothing to review."><x-fx.icon name="check" class="fx-tick-draw" /> Every VAT posting in this period carries a tax code and looks consistent. This is a check on the books, not a clearance by SARS.</x-fx.empty>
    @else
    <p>{{ $open }} of {{ count($issues) }} still open. Errors block the return until fixed in the books or accepted with a note. <x-tax.basis basis="calculated" /></p>
    <x-fx.panel title="Flagged postings" flush>
        <x-fx.table caption="VAT postings to review">
            <thead><tr><th scope="col">Entry</th><th scope="col">What is wrong</th><th scope="col">Amount</th><th scope="col">Decision</th></tr></thead>
            <tbody>
            @foreach (array_slice($issues, 0, 200) as $i)
                <tr>
                    <td data-label="Entry" class="is-lead">@if($i['entry_id'])<a class="fx-link" href="{{ route('accounting.journals.show', $i['entry_id']) }}">JE-{{ str_pad((string) $i['entry_number'], 6, '0', STR_PAD_LEFT) }}</a>@endif<span class="fx-cell-sub">{{ $i['date'] }}</span></td>
                    <td data-label="What is wrong"><x-fx.pill :kind="$i['severity'] === 'error' ? 'variance' : 'plain'">{{ $i['severity'] === 'error' ? 'Error' : 'Warning' }}</x-fx.pill> {{ $i['message'] }}@if($i['hint'])<span class="fx-cell-sub">{{ $i['hint'] }}</span>@endif</td>
                    <td data-label="Amount" class="is-num"><x-fx.money :cents="$i['amount_cents']" currency="ZAR" /></td>
                    <td data-label="Decision">
                        @if ($i['resolved'])
                            <x-fx.pill kind="ticked">{{ ucfirst($i['resolution']['resolution']) }}</x-fx.pill><span class="fx-cell-sub">{{ $i['resolution']['note'] }}</span>
                        @elseif ($canResolve && $i['journal_line_id'])
                            <form method="POST" action="{{ route('tax.vat.review.resolve') }}" class="fx-stack">
                                @csrf
                                <input type="hidden" name="journal_line_id" value="{{ $i['journal_line_id'] }}"><input type="hidden" name="issue_code" value="{{ $i['code'] }}">
                                <label class="sr-only" for="n-{{ $i['journal_line_id'] }}-{{ $i['code'] }}">Note</label>
                                <input id="n-{{ $i['journal_line_id'] }}-{{ $i['code'] }}" name="note" class="fx-input" required minlength="5" maxlength="500" placeholder="Why this is fine, or what you did">
                                <label class="sr-only" for="r-{{ $i['journal_line_id'] }}-{{ $i['code'] }}">Decision</label>
                                <select id="r-{{ $i['journal_line_id'] }}-{{ $i['code'] }}" name="resolution" class="fx-select"><option value="accepted">Accept as booked</option><option value="corrected">Corrected in the books</option><option value="ignored">Ignore</option></select>
                                <x-fx.button size="sm">Record</x-fx.button>
                            </form>
                        @else<span class="fx-hint">Open</span>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        @if (count($issues) > 200)<p class="fx-hint">The first 200 of {{ number_format(count($issues)) }} postings are listed. The count above covers all of them.</p>@endif
    </x-fx.panel>
    @endif
    @include('tax._footer')
</x-app-layout>
