<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.Finance</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { corePlugins: { preflight: false } }</script>
    <script>
        // Applied before paint to avoid a flash of the wrong theme.
        (function () {
            var stored = localStorage.getItem('dot-finance-theme');
            var theme = stored === 'light' || stored === 'dark' ? stored : 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <style>
        :root, [data-theme="dark"] {
            --accent: #4ade80; --accent-rgb: 74,222,128;
            --page-bg:#09090b; --sidebar-bg:#0d0d10; --topbar-bg:rgba(9,9,11,0.85);
            --card-bg:#141416; --card-border:rgba(255,255,255,0.07); --card-border-hover:rgba(255,255,255,0.11);
            --text-primary:#f4f4f5; --text-secondary:#a1a1aa; --text-muted:#71717a; --text-faint:#3f3f46;
            --divider:rgba(255,255,255,0.06);
            --input-bg:rgba(255,255,255,0.04); --input-border:rgba(255,255,255,0.08);
            --btn-ghost-bg:rgba(255,255,255,0.06); --btn-ghost-border:rgba(255,255,255,0.08);
            --track-bg:rgba(255,255,255,0.08);
        }
        [data-theme="light"] {
            --page-bg:#f4f4f6; --sidebar-bg:#ffffff; --topbar-bg:rgba(255,255,255,0.85);
            --card-bg:#ffffff; --card-border:rgba(15,15,20,0.09); --card-border-hover:rgba(15,15,20,0.16);
            --text-primary:#18181b; --text-secondary:#3f3f46; --text-muted:#6b7280; --text-faint:#9ca3af;
            --divider:rgba(15,15,20,0.08);
            --input-bg:#f4f4f6; --input-border:rgba(15,15,20,0.12);
            --btn-ghost-bg:rgba(15,15,20,0.05); --btn-ghost-border:rgba(15,15,20,0.1);
            --track-bg:rgba(15,15,20,0.08);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin:0; background:var(--page-bg); color:var(--text-primary); font-family:'Inter',system-ui,sans-serif; font-size:14px; line-height:1.5; transition:background .15s,color .15s; }
        .material-symbols-rounded { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; line-height:1; user-select:none; }
        [x-cloak] { display:none!important; }

        /* Sidebar */
        .sidebar { position:fixed; left:0; top:0; width:260px; height:100vh; background:var(--sidebar-bg); border-right:1px solid var(--divider); display:flex; flex-direction:column; z-index:40; overflow:hidden; }
        .sidebar::before { content:''; position:absolute; top:-80px; left:-80px; width:320px; height:320px; background:radial-gradient(circle, rgba(74,222,128,0.1) 0%, transparent 65%); pointer-events:none; }

        .sidebar-brand { padding:20px 18px 14px; display:flex; align-items:center; gap:11px; flex-shrink:0; }
        .brand-icon { width:36px; height:36px; border-radius:10px; background:rgba(74,222,128,0.12); border:1px solid rgba(74,222,128,0.22); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .brand-icon .material-symbols-rounded { font-size:18px; color:#4ade80; }
        .brand-name { font-family:'Syne',sans-serif; font-size:14.5px; font-weight:700; color:var(--text-primary); letter-spacing:-0.01em; line-height:1.2; }
        .brand-status { display:flex; align-items:center; gap:5px; margin-top:3px; }
        .live-dot { width:6px; height:6px; border-radius:50%; background:#4ade80; flex-shrink:0; animation:live-pulse 2.8s ease-in-out infinite; }
        @keyframes live-pulse { 0%,100% { opacity:1; box-shadow:0 0 0 0 rgba(74,222,128,0.45); } 60% { opacity:.6; box-shadow:0 0 0 5px rgba(74,222,128,0); } }
        .brand-subtitle { font-size:10px; font-weight:500; color:var(--text-faint); text-transform:uppercase; letter-spacing:0.09em; }

        .sidebar-divider { height:1px; background:var(--divider); margin:4px 14px 8px; }
        .sidebar-nav { padding:0 10px; flex:1; overflow-y:auto; scrollbar-width:none; }
        .sidebar-nav::-webkit-scrollbar { display:none; }
        .nav-section-label { font-size:10px; font-weight:600; color:var(--text-faint); text-transform:uppercase; letter-spacing:0.1em; padding:14px 8px 5px; }
        .nav-item { display:flex; align-items:center; gap:9px; padding:7.5px 10px; border-radius:8px; font-size:13px; font-weight:500; color:var(--text-muted); text-decoration:none; transition:background .13s,color .13s,transform .13s; margin-bottom:1px; }
        .nav-item:hover { background:var(--btn-ghost-bg); color:var(--text-secondary); transform:translateX(1px); }
        .nav-item.active { background:rgba(74,222,128,0.1); color:#22c55e; font-weight:600; }
        .nav-icon { font-size:17px; width:20px; text-align:center; flex-shrink:0; }

        .sidebar-footer { padding:10px 14px 14px; border-top:1px solid var(--divider); flex-shrink:0; }
        .user-row { display:flex; align-items:center; gap:9px; padding:8px 6px; border-radius:8px; }
        .user-avatar { width:28px; height:28px; border-radius:50%; background:rgba(74,222,128,0.18); border:1px solid rgba(74,222,128,0.28); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#22c55e; flex-shrink:0; font-family:'Syne',sans-serif; }
        .user-name { font-size:12px; font-weight:600; color:var(--text-secondary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .user-team { font-size:10px; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        /* Topbar */
        .topbar { position:fixed; top:0; left:260px; right:0; height:54px; background:var(--topbar-bg); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border-bottom:1px solid var(--divider); display:flex; align-items:center; padding:0 22px; z-index:30; gap:12px; }
        .topbar-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; color:var(--text-primary); flex:1; }
        .topbar-team { font-size:11px; color:var(--text-muted); background:var(--btn-ghost-bg); border:1px solid var(--card-border); border-radius:6px; padding:3px 8px; font-weight:500; white-space:nowrap; }
        .topbar-btn { width:30px; height:30px; border-radius:7px; border:1px solid var(--input-border); background:var(--input-bg); display:flex; align-items:center; justify-content:center; color:var(--text-muted); cursor:pointer; transition:background .13s,color .13s; text-decoration:none; flex-shrink:0; }
        .topbar-btn:hover { background:var(--btn-ghost-bg); color:var(--text-secondary); }
        .topbar-btn .material-symbols-rounded { font-size:17px; }

        /* Content */
        .content-wrap { margin-left:260px; padding-top:54px; min-height:100vh; }

        /* Shared UI tokens */
        .dot-card { background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; }
        .dot-card:hover { border-color:var(--card-border-hover); }
        .metric-val { font-family:'JetBrains Mono',monospace; font-weight:500; letter-spacing:-0.02em; }
        .dot-input { background:var(--input-bg); border:1px solid var(--input-border); border-radius:8px; color:var(--text-primary); font-family:'Inter',sans-serif; font-size:13px; padding:8px 12px; width:100%; transition:border-color .15s,box-shadow .15s; outline:none; }
        .dot-input:focus { border-color:rgba(74,222,128,0.45); box-shadow:0 0 0 3px rgba(74,222,128,0.07); }
        .dot-input::placeholder { color:var(--text-faint); }
        .dot-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:all .14s; border:none; text-decoration:none; font-family:'Inter',sans-serif; }
        .dot-btn-primary { background:#4ade80; color:#09090b; }
        .dot-btn-primary:hover { filter:brightness(1.1); }
        .dot-btn-ghost { background:var(--btn-ghost-bg); color:var(--text-secondary); border:1px solid var(--btn-ghost-border); }
        .dot-btn-ghost:hover { background:var(--input-border); color:var(--text-primary); }
        .dot-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:100px; font-size:11px; font-weight:600; }
        .dot-badge-accent { background:rgba(74,222,128,0.12); color:#22c55e; }
        select.dot-input option { background:var(--card-bg); color:var(--text-primary); }
    </style>
    @livewireStyles
    <script defer src="https://unpkg.com/alpinejs@3.10.2/dist/cdn.min.js"></script>
</head>
<body>
    <x-banner />

    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon" style="background:#fff;padding:4px;">
                <img src="{{ asset('images/mark.png') }}" alt="Dot.Finance" style="width:100%;height:100%;object-fit:contain;" />
            </div>
            <div>
                <div class="brand-name">Dot.Finance</div>
                <div class="brand-status">
                    <div class="live-dot"></div>
                    <span class="brand-subtitle">Financial Intelligence</span>
                </div>
            </div>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">dashboard</span>
                Dashboard
            </a>
            <a href="{{ route('transactions.index') }}" class="nav-item {{ request()->routeIs('transactions.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">receipt_long</span>
                Transactions
            </a>
            <a href="{{ route('transfers.create') }}" class="nav-item {{ request()->routeIs('transfers.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">swap_horiz</span>
                Transfers
            </a>
            <a href="{{ route('accounts.index') }}" class="nav-item {{ request()->routeIs('accounts.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">account_balance_wallet</span>
                Accounts
            </a>
            <a href="{{ route('budgets.index') }}" class="nav-item {{ request()->routeIs('budgets.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">savings</span>
                Budgets
            </a>
            <a href="{{ route('categories.index') }}" class="nav-item {{ request()->routeIs('categories.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">sell</span>
                Categories
            </a>
            <div class="nav-section-label">Control</div>
            <a href="{{ route('reconciliation.index') }}" class="nav-item {{ request()->routeIs('reconciliation.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">fact_check</span>
                Reconciliation
            </a>
            <div class="sidebar-divider" style="margin:10px 0;"></div>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">manage_accounts</span>
                Profile & Settings
            </a>
        </nav>

        @auth
        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                <div style="min-width:0;flex:1;">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    <div class="user-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</div>
                </div>
            </div>
        </div>
        @endauth
    </aside>

    <header class="topbar">
        <div class="topbar-title">
            @isset($header){{ $header }}@else Dot.Finance
            @endisset
        </div>
        @auth
        <span class="topbar-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</span>
        @endauth
        <button type="button" class="topbar-btn" title="Toggle dark / light mode" aria-label="Toggle dark or light mode"
            onclick="(function(){
                var html = document.documentElement;
                var next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
                html.setAttribute('data-theme', next);
                localStorage.setItem('dot-finance-theme', next);
            })()">
            <span class="material-symbols-rounded">dark_mode</span>
        </button>
        <a href="{{ route('profile.show') }}" class="topbar-btn" title="Profile">
            <span class="material-symbols-rounded">account_circle</span>
        </a>
    </header>

    @livewire('navigation-menu')

    <div class="content-wrap">
        <main>{{ $slot }}</main>
    </div>

    @stack('modals')
    @livewireScripts
</body>
</html>
