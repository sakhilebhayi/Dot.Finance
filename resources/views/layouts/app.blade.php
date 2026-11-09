<!DOCTYPE html>
<html lang="en-ZA">
<head>
    @include('layouts.partials-head')
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
@endphp
<body>
    <a href="#main" class="fx-skip">Skip to the content</a>
    <x-banner />

    <div class="fx-app">
        <aside id="app-sidebar" class="fx-side sidebar" data-shell-drawer aria-label="Sidebar" tabindex="-1">
            <div class="fx-side__head">
                <a href="{{ route('dashboard') }}" class="fx-side__brand" aria-label="Dot.Finance, command centre">
                    <x-fx.mark />
                    <span class="fx-wordmark">Dot.Finance<small>{{ $activeLedger?->isCompany() ? 'Company ledger' : 'Personal ledger' }}</small></span>
                </a>
                <button type="button" class="fx-iconbtn fx-side__close" data-shell-close aria-label="Close menu"><x-fx.icon name="x" /></button>
            </div>

            <div class="fx-side__scroll">
                <x-ledger-switcher />

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
                    <details class="fx-nav__group" @if ($group['open']) open @endif>
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

            @auth
            <div class="fx-side__foot">
                <div class="fx-user">
                    <span class="fx-avatar" aria-hidden="true">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>
                    <span class="fx-user__text">
                        <span class="fx-user__name">{{ Auth::user()->name }}</span>
                        <span class="fx-user__sub">{{ $ledgerLabel }}</span>
                    </span>
                </div>
                <div class="fx-account shell-account">
                    <a href="{{ route('profile.show') }}" class="team-row" @if (request()->routeIs('profile.show')) aria-current="true" @endif>
                        <x-fx.icon name="settings" aria-hidden="true" />
                        <span class="team-label">Profile and settings</span>
                    </a>
                    @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
                    <details class="team-switch">
                        <summary>
                            <x-fx.icon name="users" />
                            <span class="team-label">Team: {{ Auth::user()->currentTeam->name }}</span>
                            <x-fx.icon name="chevron-down" />
                        </summary>
                        <div class="team-switch-list">
                            <a class="team-row" href="{{ route('teams.show', Auth::user()->currentTeam->id) }}">
                                <x-fx.icon name="settings" />
                                <span class="team-label">Team settings</span>
                            </a>
                            @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                            <a class="team-row" href="{{ route('teams.create') }}">
                                <x-fx.icon name="plus" />
                                <span class="team-label">Create new team</span>
                            </a>
                            @endcan
                            @if (Auth::user()->allTeams()->count() > 1)
                            <div class="team-switch-label">Switch teams</div>
                            @foreach (Auth::user()->allTeams() as $switchTeam)
                            <form method="POST" action="{{ route('current-team.update') }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="team_id" value="{{ $switchTeam->id }}">
                                <button type="submit" class="team-row" @if (Auth::user()->isCurrentTeam($switchTeam)) aria-current="true" @endif>
                                    <x-fx.icon :name="Auth::user()->isCurrentTeam($switchTeam) ? 'circle-check' : 'arrow-left-right'" />
                                    <span class="team-label">{{ $switchTeam->name }}</span>
                                </button>
                            </form>
                            @endforeach
                            @endif
                        </div>
                    </details>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="logout-btn">
                            <x-fx.icon name="log-out" />
                            <span class="team-label">Log out</span>
                        </button>
                    </form>
                </div>
            </div>
            @endauth
        </aside>

        <div class="fx-column">
            <header class="fx-top topbar">
                <a href="{{ route('dashboard') }}" class="fx-top__brand" aria-label="Dot.Finance, command centre">
                    <x-fx.mark />
                    <span class="fx-wordmark">Dot.Finance</span>
                </a>

                <x-fx.state-line :items="$fxState" />

                <div class="fx-top__tools">
                    @includeIf('intel.partials.bell')
                    @if (fx_route_exists('transactions.index'))
                    <form class="fx-search" role="search" method="GET" action="{{ route('transactions.index') }}">
                        <x-fx.icon name="search" />
                        <label for="fx-search" class="sr-only">Search transactions</label>
                        <input id="fx-search" type="search" name="search" placeholder="Search transactions" autocomplete="off" />
                    </form>
                    @endif
                    <button type="button" class="fx-cmdbtn" data-fx-command-open aria-keyshortcuts="Control+K Meta+K">Quick actions <kbd>Ctrl K</kbd></button>
                    <button type="button" class="fx-iconbtn fx-iconbtn--phone" data-fx-command-open aria-label="Search and quick actions"><x-fx.icon name="search" /></button>
                    <button type="button" class="fx-iconbtn" data-fx-theme aria-label="Theme: Match system. Press to change." title="Theme: Match system">
                        <span data-theme-icon="null"><x-fx.icon name="monitor" /></span>
                        <span data-theme-icon="light" hidden><x-fx.icon name="sun" /></span>
                        <span data-theme-icon="dark" hidden><x-fx.icon name="moon" /></span>
                    </button>
                </div>
            </header>

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

    <div class="fx-scrim" data-shell-backdrop aria-hidden="true"></div>

    <nav class="fx-bar" aria-label="Quick navigation">
        <a href="{{ route('dashboard') }}" class="fx-bar__item" @if (request()->routeIs('dashboard')) aria-current="page" @endif><x-fx.icon name="layout-dashboard" />Home</a>
        <a href="{{ route('transactions.index') }}" class="fx-bar__item" @if (request()->routeIs('transactions.*')) aria-current="page" @endif><x-fx.icon name="receipt-text" />Activity</a>
        @if ($canRecord)
        <a href="{{ route('transactions.create') }}" class="fx-bar__item fx-bar__item--add" aria-label="Add a transaction"><span class="fx-bar__plus"><x-fx.icon name="plus" /></span><span class="fx-bar__label">Add</span></a>
        @else
        <a href="{{ route('budgets.index') }}" class="fx-bar__item" @if (request()->routeIs('budgets.*')) aria-current="page" @endif><x-fx.icon name="piggy-bank" />Budgets</a>
        @endif
        <a href="{{ route('accounts.index') }}" class="fx-bar__item" @if (request()->routeIs('accounts.*')) aria-current="page" @endif><x-fx.icon name="landmark" />Accounts</a>
        <button type="button" class="fx-bar__item shell-menu" data-shell-menu aria-controls="app-sidebar" aria-expanded="false"><x-fx.icon name="menu" /><span>More</span></button>
    </nav>

    <x-fx.command :nav="$fxNav" :quick="$fxQuick" />
    <x-fx.confirm />
    <x-fx.toasts />

    @stack('modals')
    @livewireScripts(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
</body>
</html>
