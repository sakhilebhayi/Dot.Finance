<!DOCTYPE html>
<html lang="en-ZA" class="dot-roboto" data-dh-theme-key="dot-finance-theme" data-dh-theme-default="system">
<head>
    @include('layouts.partials-head')
    {{-- The shared Dot UI (header, search pill and platform switcher, dot-ui 0.6.0): self-hosted, no inline style or script. Roboto and the theme
         boot are in the shared head partial. Its colours are mapped to the Finance tokens in resources/css/shell.css (the block marked "Dot UI"). --}}
    <link rel="stylesheet" href="/dot-ui/css/dot-tokens.css">
    <link rel="stylesheet" href="/dot-ui/css/dot-search.css">
    <link rel="stylesheet" href="/dot-ui/css/dot-switcher.css">
    <link rel="stylesheet" href="/dot-ui/css/dot-header.css">
    <script src="/dot-ui/js/dot-search.js" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    <script src="/dot-ui/js/dot-search-results.js" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    <script src="/dot-ui/js/dot-switcher.js" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    <script src="/dot-ui/js/dot-nav.js" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    <title>{{ $title ?? (isset($header) ? strip_tags((string) $header) : null) ?? 'Command centre' }} · Dot.Finance</title>
    @livewireStyles(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
    {{-- No stand-alone Alpine here: Livewire 3 bundles and starts its own (@livewireScripts). A second copy
         replaces window.Alpine and breaks every Livewire re-render ('Alpine.cloneNode is not a function'). --}}
</head>
@php
    // $activeLedger (the person's own ledger or one of their companies) comes from the view composer in AppServiceProvider.
    $ledgerLabel = $activeLedger?->isCompany() ? $activeLedger->label() : 'Personal';
    $ledgerAccess = Auth::check() ? \App\Support\Ledger\LedgerAccess::current(Auth::user()) : null;
    $canRecord = $ledgerAccess?->canRecordEntries() ?? false;
    // The platform switcher asks InfoDot about the company the entitlement check asks about (a {team} in the address wins when the
    // person belongs to it, otherwise the active ledger). The personal ledger has no company: the switcher shows its personal state.
    $switcherCompany = Auth::check() ? app(\App\Support\Ledger\EntitledCompany::class)->for(request(), Auth::user()) : null;

    $user = Auth::user();
    // The account menu: Finance's own account actions (profile, team, the team switch, sign out). The ledger the books are open on is the
    // menu's second line, so a person on a phone always sees which books they are in.
    $account = $user ? [
        'name' => $user->name,
        'email' => $user->email,
        'team' => $ledgerLabel,
        'links' => array_values(array_filter([
            ['label' => 'Profile and settings', 'href' => route('profile.show'), 'current' => request()->routeIs('profile.show')],
            Laravel\Jetstream\Jetstream::hasTeamFeatures() && $user->currentTeam ? ['label' => 'Team settings', 'href' => route('teams.show', $user->currentTeam->id), 'current' => request()->routeIs('teams.show')] : null,
            Laravel\Jetstream\Jetstream::hasTeamFeatures() && $user->can('create', Laravel\Jetstream\Jetstream::newTeamModel()) ? ['label' => 'Create new team', 'href' => route('teams.create')] : null,
            Laravel\Jetstream\Jetstream::hasTeamFeatures() && $user->allTeams()->count() > 1 ? ['heading' => 'Switch teams'] : null,
            ...(Laravel\Jetstream\Jetstream::hasTeamFeatures() && $user->allTeams()->count() > 1
                ? $user->allTeams()->map(fn ($switchTeam) => ['label' => $switchTeam->name, 'current' => $user->isCurrentTeam($switchTeam), 'form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $switchTeam->id]]])->all()
                : []),
        ])),
        'signOut' => ['action' => route('logout')],
        'theme' => true,
    ] : null;
    // "Go to" chips under the empty search box: places that exist, are allowed and are used most.
    $fxJump = collect($fxNav)->flatMap(fn ($g) => $g['items'])->filter(fn ($i) => $i['available'] && in_array($i['key'], ['transactions', 'accounts', 'invoices', 'bills', 'reports-hub'], true))->values();
@endphp
<body>
    <x-banner />

    {{-- The shared Dot header (dot-ui 0.6.0). Dot.Finance keeps its sidebar as the primary navigation (60 destinations in 8 groups, too many for a bar), so
         the bar has no nav list of its own: drawer-target adopts the sidebar as the phone menu. The search is centred by the header's grid. --}}
    <x-dot.header id="dh" :brand-href="route('dashboard')" brand-label="Dot.Finance, command centre" drawer-target="app-sidebar" skip="#main"
                  :context="$activeLedger?->isCompany() ? $activeLedger->label() : null" :account="$account"
                  :guest="Auth::check() ? null : ['login' => ['label' => 'Log in', 'href' => route('login')]]">
        <x-slot:brand>
            {{-- The original logo files, untouched: the day one on paper, the light one at night (dot-ui swaps the pair). --}}
            <img class="dh-logo-day" src="{{ asset('images/logo.png') }}" alt="" width="1181" height="886" decoding="async">
            <img class="dh-logo-night" src="{{ asset('images/logo-light.png') }}" alt="" width="1181" height="886" decoding="async">
        </x-slot:brand>
        @auth
        <x-slot:search>
            {{-- Search for every page and width (the phone gets dot-ui's sheet). Enter and the arrow still search the transactions (the same GET form,
                 the same parameter); typing also lists matches in the books that are open (App\Support\Search\BooksSearch, via search.suggest).
                 Ctrl K belongs to the quick-actions bar (command.js), so the pill shows "/" and binds nothing: "/" focuses it (search.js). --}}
            <x-dot.search name="search" id="fx-search" :action="route('transactions.index')" placeholder="Search the books" label="Search transactions, accounts, customers, suppliers, invoices, bills and tax"
                          hint="/" :hint-key="false" results-nav escape-clears loading-text="Looking in the books..."
                          :form-attributes="['data-fx-search' => route('search.suggest')]">
                <x-slot:results>
                    <div class="dsr-quick">
                        <div class="dsr-head">Go to</div>
                        <div class="dsr-chips">
                            @foreach ($fxJump as $jump)
                            <a class="dsr-chip" href="{{ $jump['url'] }}">{{ $jump['label'] }}</a>
                            @endforeach
                            <button type="button" class="dsr-chip" data-fx-command-open>Quick actions <span class="fx-chipkey">Ctrl K</span></button>
                        </div>
                        <p class="dsr-foot">Type two letters to find an entry, account, customer, supplier, invoice, bill or tax period. Enter searches the transactions.</p>
                    </div>
                </x-slot:results>
            </x-dot.search>
        </x-slot:search>
        <x-slot:tools>
            <x-dot.switcher platform="finance" :company="$switcherCompany?->infodot_team_uuid" :company-name="$switcherCompany?->name" logos="/dot-ui/img/platforms" />
            @if (fx_route_exists('intel.notifications.index'))
            <x-dot.bell :href="route('intel.notifications.index')" :count="$intelUnread ?? 0" always />
            @endif
        </x-slot:tools>
        @endauth
    </x-dot.header>

    <div class="fx-app">
        <aside id="app-sidebar" class="fx-side sidebar" data-shell-drawer aria-label="Sidebar" tabindex="-1">
            {{-- Phones: the header's menu button opens this sidebar (dot-ui drawer-target). It is the only place the close button lives. --}}
            <div class="fx-side__head">
                <span class="fx-side__title">Menu</span>
                <button type="button" class="fx-iconbtn fx-side__close" data-dh-close aria-label="Close menu"><x-fx.icon name="x" /></button>
            </div>

            <div class="fx-side__scroll">
                <x-ledger-switcher />

                <button type="button" class="fx-cmdbtn" data-fx-command-open aria-keyshortcuts="Control+K Meta+K"><x-fx.icon name="search" /><span>Quick actions</span><kbd>Ctrl K</kbd></button>

                @if (! empty($fxStart))
                <section class="fx-start" aria-labelledby="fx-start-h">
                    <h2 id="fx-start-h" class="fx-start__title">Start here</h2>
                    <ol class="fx-start__list" role="list">
                        @foreach ($fxStart as $step)
                        <li @class(['is-done' => $step['done']])>
                            @if ($step['done'])<x-fx.icon name="check" /><span>{{ $step['label'] }}<span class="sr-only"> (done)</span></span>
                            @else<a href="{{ $step['url'] }}">{{ $step['label'] }}</a>@endif
                        </li>
                        @endforeach
                    </ol>
                </section>
                @endif

                <nav class="fx-nav" aria-label="Main">
                    @foreach ($fxNav as $group)
                    {{-- name="fx-nav": the browser keeps one group open at a time (an accordion), so 60 destinations are never a wall. --}}
                    <details class="fx-nav__group" name="fx-nav" @if ($group['open']) open @endif>
                        <summary><span>{{ $group['label'] }}</span><x-fx.icon name="chevron-right" /></summary>
                        <ul class="fx-nav__list" role="list">
                            @foreach ($group['items'] as $item)
                            <li>
                                @if ($item['available'])
                                <a href="{{ $item['url'] }}" class="fx-nav__item" @if ($item['active']) aria-current="page" @endif><x-fx.icon :name="$item['icon']" />{{ $item['label'] }}</a>
                                @else
                                <span class="fx-nav__item" role="link" aria-disabled="true"><x-fx.icon :name="$item['icon']" />{{ $item['label'] }}<span class="fx-nav__soon">Soon</span></span>
                                @endif
                            </li>
                            @endforeach
                        </ul>
                    </details>
                    @endforeach
                </nav>
            </div>
        </aside>

        <div class="fx-column">
            {{-- What needs the owner, as one slim line under the header at every width (it used to share the header's row). --}}
            <div class="fx-statebar"><x-fx.state-line :items="$fxState" /></div>

            <main id="main" class="fx-main" tabindex="-1">
                <div @class(['fx-page', 'page-pad' => ! $bare])>
                    @if ($activeLedger?->isCompany() && $ledgerAccess && ! $ledgerAccess->canRecordEntries())
                    <div class="fx-notice" role="note">
                        <x-fx.icon name="lock" />
                        <p>You have read-only access to {{ $activeLedger->label() }}: everything here, reports and the forecast included, can be read but not changed. A company owner or admin can change your role in InfoDot.</p>
                    </div>
                    @endif
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    {{-- Four places, each 44px or more, always reachable. Everything else is behind the header's menu button (the sidebar). --}}
    <nav class="fx-bar" aria-label="Quick navigation">
        <a href="{{ route('dashboard') }}" class="fx-bar__item" @if (request()->routeIs('dashboard')) aria-current="page" @endif><x-fx.icon name="layout-dashboard" />Home</a>
        <a href="{{ route('transactions.index') }}" class="fx-bar__item" @if (request()->routeIs('transactions.*')) aria-current="page" @endif><x-fx.icon name="receipt-text" />Activity</a>
        @if ($canRecord)
        <a href="{{ route('transactions.create') }}" class="fx-bar__item fx-bar__item--add" aria-label="Add a transaction"><span class="fx-bar__plus"><x-fx.icon name="plus" /></span><span class="fx-bar__label">Add</span></a>
        @else
        <a href="{{ route('budgets.index') }}" class="fx-bar__item" @if (request()->routeIs('budgets.*')) aria-current="page" @endif><x-fx.icon name="piggy-bank" />Budgets</a>
        @endif
        <a href="{{ route('accounts.index') }}" class="fx-bar__item" @if (request()->routeIs('accounts.*')) aria-current="page" @endif><x-fx.icon name="landmark" />Accounts</a>
    </nav>

    <x-fx.command :nav="$fxNav" :quick="$fxQuick" />
    <x-fx.confirm />
    <x-fx.toasts />

    @stack('modals')
    @livewireScripts(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
</body>
</html>
