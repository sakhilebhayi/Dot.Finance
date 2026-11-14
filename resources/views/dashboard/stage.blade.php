{{-- The selected account (detail). Drawn for the first row, or the account named by ?account=, with no script at all; the
     script only swaps its words when another row is picked. Hidden below 1024px, where each row is a link of its own. --}}
@if ($selectedAccount)
@php
    $sel = $selectedAccount;
    $selFacts = $accountFacts[$sel->id] ?? null;
    $selN = $selFacts ? (int) $selFacts->entries : 0;
    $selLast = $selFacts && $selFacts->last_date ? \Illuminate\Support\Carbon::parse($selFacts->last_date)->format('Y-m-d') : 'None yet';
    $selB = $sel->balanceCents();
    $selBal = $sel->type === 'credit'
        ? ($selB < 0 ? 'Owed '.$full(-$selB, $sel->currency) : ($selB > 0 ? 'Credit balance '.$full($selB, $sel->currency) : 'Nothing owed'))
        : $full($selB, $sel->currency);
@endphp
<aside class="fxd-sec fxd-stage fxd-arrive" aria-labelledby="fxd-stage-h" data-fxd-stage>
    <div class="fxd-sec__h"><h2 id="fxd-stage-h">Selected account</h2></div>
    <div class="fxd-stage__body" data-fxd-settle aria-live="polite">
        <h3 class="fxd-stage__name" data-s="name">{{ $sel->name }}</h3>
        <p class="fxd-meta" data-s="type">{{ \App\Support\FigureWords::accountType((string) $sel->type) }}@if ($sel->institution), {{ $sel->institution }}@endif</p>
        <dl class="fxd-facts">
            <dt>Currency</dt><dd data-s="cur">{{ $sel->currency }}</dd>
            <dt>Opening balance</dt><dd class="is-num"><span class="fx-fig" data-s="open">{{ $full((int) $sel->opening_balance_cents, $sel->currency) }}</span></dd>
            <dt>Entries recorded</dt><dd class="is-num"><span class="fx-fig" data-s="n">{{ number_format($selN) }}</span></dd>
            <dt>Last entry dated</dt><dd class="is-num"><span class="fx-fig" data-s="last">{{ $selLast }}</span></dd>
            <dt class="fxd-tot">Balance</dt><dd class="fxd-tot is-num"><span class="fx-fig" data-s="bal">{{ $selBal }}</span></dd>
        </dl>
        <div class="fxd-acts">
            <a class="fx-btn" href="{{ route('transactions.index', ['account_id' => $sel->id]) }}" data-s-href="entries">Open the entries</a>
            @can('update', $sel)
            <a class="fxd-quiet" href="{{ route('accounts.edit', $sel) }}" data-s-href="edit">Edit account</a>
            @endcan
        </div>
    </div>
</aside>
@endif
