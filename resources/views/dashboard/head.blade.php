{{-- The head and the headline: the landing. Everything the R-50 landing pinned stays here (one data-landing section, the one
     primary action, the counts and their markers, the ledger-switch form, the way back to InfoDot). --}}
@php
    $lead = $currencyOrder[0] ?? null;
    $leadRow = $lead ? $position[$lead] : null;
@endphp
<section class="fxd-land" aria-labelledby="fxd-title" data-landing>
    <header class="fxd-head">
        <h1 id="fxd-title">Command centre</h1>
        <div class="fxd-head__sub">
            <span data-landing-date>{{ now()->format('l, j F Y').'. '.($company ? $activeLedger->label() : 'Your own ledger').'. '.now()->format('F Y').' to date.' }}</span>
            <nav class="fxd-back" aria-label="Dot ecosystem">
                <a href="{{ $infodot }}/home">InfoDot</a>
                <a href="{{ $infodot }}/platforms">Platforms</a>
            </nav>
        </div>
        <div class="fxd-head__act">
            @if ($landAction['url'] !== route('transactions.index') && $entryCount > 0)
            <a href="{{ route('transactions.index') }}" class="fxd-quiet">Read the entries</a>
            @elseif (fx_route_exists('reports.index') && $entryCount > 0)
            <a href="{{ route('reports.index') }}" class="fxd-quiet">Open the reports</a>
            @endif
            <x-fx.button variant="primary" :href="$landAction['url']" :icon="$landAction['icon']" data-primary-action>{{ $landAction['label'] }}</x-fx.button>
        </div>
    </header>

    @if ($otherCompanies !== [])
    <form method="POST" action="{{ route('ledger.context.switch') }}" class="fxd-switch" aria-label="Open a company's books">
        @csrf
        <p>You also belong to {{ count($otherCompanies) === 1 ? 'a company' : 'companies' }} here. Open {{ count($otherCompanies) === 1 ? 'its' : 'their' }} books:</p>
        <div class="fxd-switch__opts">
            @foreach ($otherCompanies as $choice)
            <x-fx.button size="sm" name="context" :value="$choice->teamId" icon="building-2">{{ $choice->label() }}</x-fx.button>
            @endforeach
        </div>
    </form>
    @endif

    <div class="fxd-say">
        <div class="fxd-say__text">
            @if ($ledgerEmpty)
            <div class="fxd-blank" data-landing-empty>
                <h2 class="fxd-reveal">Nothing has been recorded yet.</h2>
                <p>{{ $company ? $activeLedger->label().' has' : 'Your ledger has' }} no account and no entry, so this page has no figures to show. They appear once something is recorded, and each one will link to the rows it came from.</p>
                @unless ($canAddAccount)<p>A company owner or admin needs to add the first account.</p>@endunless
            </div>
            @else
            <h2 class="fxd-reveal">
                @if (! $leadRow)
                No account is set up here yet, so there is no cash figure.
                @elseif ($leadRow['cash_accounts'] > 0)
                <x-fx.money :cents="$leadRow['cash_cents']" :currency="$lead" /> in cash {{ $leadRow['cash_accounts'] === 1 ? 'in one account' : 'across '.$leadRow['cash_accounts'].' accounts' }}.
                @else
                No cash accounts in {{ $lead }}.
                @endif
                @if ($leadRow)
                {{ now()->format('F') }} so far: <x-fx.money :cents="$leadRow['net_cents']" :currency="$lead" :signed="true" /> net.
                @endif
            </h2>
            <p class="fxd-say__facts">@if ($accountCount > 0)<span class="fx-fig" data-landing-count="accounts">{{ number_format($accountCount) }}</span> {{ $accountCount === 1 ? 'account' : 'accounts' }}@else no accounts yet @endif and @if ($entryCount > 0)<span class="fx-fig" data-landing-count="entries">{{ number_format($entryCount) }}</span> {{ $entryCount === 1 ? 'entry' : 'entries' }}@else no entries yet @endif@if ($lastEntry), the last dated <span class="fx-fig fx-date" data-landing-last>{{ $lastEntry->date->format('Y-m-d') }}</span>@endif. Every figure here traces back to the entries behind it; the tick beside it opens those rows.</p>
            @endif
        </div>
        <svg class="fxd-sheet" viewBox="0 0 240 128" width="240" height="128" aria-hidden="true" focusable="false">
            <g class="fxd-sheet__lines">
                <line x1="0" y1="22" x2="240" y2="22" />
                <line x1="0" y1="52" x2="240" y2="52" />
                <line x1="0" y1="82" x2="240" y2="82" class="is-ink" />
                <line x1="0" y1="108" x2="240" y2="108" class="is-ink" />
                <line x1="0" y1="115" x2="240" y2="115" class="is-ink" />
            </g>
            @unless ($ledgerEmpty)
            <path class="fxd-sheet__tick" d="M206 31l7 7 14-17" pathLength="100" />
            @endunless
        </svg>
    </div>
</section>
