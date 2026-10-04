<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="tableplay-reverb-key" content="{{ config('reverb.apps.apps.0.key') }}">
    <meta name="tableplay-reverb-port" content="{{ config('tableplay.reverb_public_port') ?: config('reverb.servers.reverb.port', 8080) }}">
    <meta name="tableplay-reverb-host" content="{{ config('tableplay.reverb_public_host') }}">
    <meta name="tableplay-reverb-scheme" content="{{ config('tableplay.reverb_public_scheme') }}">
    <meta name="theme-color" content="#143c35">
    <title>@yield('title', 'Workspace') &middot; {{ $appSettings?->restaurant_name ?: 'TablePlay' }}</title>
    <link rel="icon" href="{{ route('brand.favicon',['v'=>$appSettings?->updated_at?->timestamp]) }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="@guest auth-page @else app-page @endguest" style="--brand-accent: {{ $appSettings?->brand_color ?: '#ef6a3a' }};--brand-deep: {{ $appSettings?->secondary_color ?: '#123c35' }}">
@guest
    <main class="auth-shell">@yield('content')</main>
@else
    <div class="app-shell" data-app-shell>
        <div class="sidebar-scrim" data-sidebar-close></div>
        <aside class="sidebar" aria-label="Primary navigation">
            <div class="sidebar__brand">
                <a href="/"><x-brand light /></a>
                <button class="icon-button sidebar__close" type="button" data-sidebar-close aria-label="Close menu"><x-icon name="close" /></button>
            </div>
            <nav class="sidebar__nav">
                @if(auth()->user()->hasRole('superadmin'))
                    <span class="nav-label">Platform</span>
                    <a class="nav-link {{ request()->routeIs('superadmin.*') ? 'active' : '' }}" href="{{ route('superadmin.index') }}"><x-icon name="license" /><span>Command center</span></a>
                @endif
                @if(auth()->user()->hasRole('restaurant_owner'))
                    <span class="nav-label">Restaurant account</span>
                    <a class="nav-link {{ request()->routeIs('account.*') ? 'active' : '' }}" href="{{ route('account.index') }}"><x-icon name="setup" /><span>Trial onboarding</span></a>
                    <a class="nav-link" href="{{ route('privacy') }}"><x-icon name="license" /><span>Privacy policy</span></a>
                    <a class="nav-link" href="{{ route('terms') }}"><x-icon name="report" /><span>Terms of service</span></a>
                @endif
                @if(auth()->user()->hasRole('admin'))
                    @php
                        $hasPlanFeature = static fn (string $feature): bool => (bool) data_get($licenseState, 'licensed', false)
                            && (bool) data_get($licenseState, 'features.'.$feature, false);
                        $planName = data_get($licenseState, 'plan.name', 'No active plan');
                    @endphp
                    <span class="nav-label">Manage</span>
                    <a class="nav-link {{ request()->routeIs('admin.overview') ? 'active' : '' }}" href="{{ route('admin.overview') }}"><x-icon name="dashboard" /><span>Overview</span></a>
                    <a class="nav-link {{ request()->routeIs('admin.tables') ? 'active' : '' }}" href="{{ route('admin.tables') }}"><x-icon name="tables" /><span>Tables &amp; devices</span></a>
                    <a class="nav-link {{ request()->routeIs('admin.menu') ? 'active' : '' }}" href="{{ route('admin.menu') }}"><x-icon name="menu" /><span>Menu</span></a>
                    <a class="nav-link {{ request()->routeIs('admin.team') ? 'active' : '' }}" href="{{ route('admin.team') }}"><x-icon name="team" /><span>Team</span></a>
                    @if($hasPlanFeature('games'))
                        <a class="nav-link {{ request()->routeIs('admin.games') ? 'active' : '' }}" href="{{ route('admin.games') }}"><x-icon name="game" /><span>Games</span></a>
                    @else
                        <a class="nav-link nav-link--locked" href="{{ route('admin.license.index') }}" title="Games are not included in {{ $planName }}"><x-icon name="game" /><span>Games</span><em>Upgrade</em></a>
                    @endif
                    @if($hasPlanFeature('advanced_reports'))
                        <a class="nav-link {{ request()->routeIs('admin.reports') ? 'active' : '' }}" href="{{ route('admin.reports') }}"><x-icon name="report" /><span>Reports</span></a>
                    @else
                        <a class="nav-link nav-link--locked" href="{{ route('admin.license.index') }}" title="Advanced reports are not included in {{ $planName }}"><x-icon name="report" /><span>Reports</span><em>Upgrade</em></a>
                    @endif
                    <span class="nav-label nav-label--spaced">Configure</span>
                    <a class="nav-link {{ request()->routeIs('admin.setup.*') ? 'active' : '' }}" href="{{ route('admin.setup.index') }}"><x-icon name="setup" /><span>Setup guide</span></a>
                    <a class="nav-link {{ request()->routeIs('admin.settings') ? 'active' : '' }}" href="{{ route('admin.settings') }}"><x-icon name="settings" /><span>Restaurant settings</span></a>
                    <a class="nav-link {{ request()->routeIs('admin.app-updates.*') ? 'active' : '' }}" href="{{ route('admin.app-updates.index') }}"><x-icon name="update" /><span>App updates</span></a>
                    <a class="nav-link {{ request()->routeIs('admin.license.*') ? 'active' : '' }}" href="{{ route('admin.license.index') }}"><x-icon name="license" /><span>Licence</span></a>
                    @if($hasPlanFeature('automation'))
                        <a class="nav-link {{ request()->routeIs('admin.automation.*') ? 'active' : '' }}" href="{{ route('admin.automation.index') }}"><x-icon name="automation" /><span>Automation center</span></a>
                    @else
                        <a class="nav-link nav-link--locked" href="{{ route('admin.license.index') }}" title="Automation is not included in {{ $planName }}"><x-icon name="automation" /><span>Automation center</span><em>Upgrade</em></a>
                    @endif
                    <a class="nav-link {{ request()->routeIs('admin.system*') ? 'active' : '' }}" href="{{ route('admin.system') }}"><x-icon name="health" /><span>System health</span></a>
                @endif
                @if(auth()->user()->hasRole('admin','counter'))
                    <span class="nav-label nav-label--spaced">Operations</span>
                    <a class="nav-link {{ request()->routeIs('counter.*') ? 'active' : '' }}" href="{{ route('counter.index') }}"><x-icon name="counter" /><span>Counter</span></a>
                @endif
                @if(auth()->user()->hasRole('admin','kitchen'))
                    <a class="nav-link {{ request()->routeIs('kitchen.*') ? 'active' : '' }}" href="{{ route('kitchen.index') }}"><x-icon name="kitchen" /><span>Kitchen display</span></a>
                @endif
            </nav>
            <div class="sidebar__footer">
                <div class="network-chip" title="Real-time connection status"><i></i><span data-connection-label>Connecting</span></div>
                <div class="sidebar-user"><span class="avatar">{{ str(auth()->user()->name)->substr(0, 1)->upper() }}</span><span><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role->display_name }}</small></span></div>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="nav-link nav-link--button" type="submit"><x-icon name="logout" /><span>Sign out</span></button></form>
            </div>
        </aside>
        <section class="workspace">
            <header class="topbar">
                <div class="topbar__title">
                    <button class="icon-button mobile-menu" type="button" data-sidebar-open aria-label="Open menu"><x-icon name="menu-toggle" /></button>
                    <div><span class="eyebrow">@yield('eyebrow', 'TablePlay workspace')</span><h1>@yield('page-title', 'Dashboard')</h1></div>
                </div>
                <div class="topbar__tools"><span class="live-clock" data-live-clock></span><button class="icon-button" type="button" data-theme-toggle aria-label="Toggle dark mode"><span data-theme-icon><x-icon name="moon" /></span></button><span class="avatar avatar--top">{{ str(auth()->user()->name)->substr(0, 1)->upper() }}</span></div>
            </header>
            <main class="workspace__content">
                @if(auth()->user()->hasRole('admin','counter','kitchen','waiter'))
                    @foreach(data_get($licenseState,'warnings',[]) as $licenseWarning)
                        <section class="card" style="margin-bottom:16px;border-color:{{ $licenseWarning['level']==='danger'?'#fecaca':'#fde68a' }}"><div class="card__body"><strong>{{ $licenseWarning['message'] }}</strong> @if(auth()->user()->hasRole('admin'))<a href="{{ route('admin.license.index') }}">Open licence settings</a>@endif</div></section>
                    @endforeach
                @endif
                @yield('content')
            </main>
        </section>
    </div>
    <div class="toast-stack" data-toast-stack aria-live="polite" aria-atomic="false">
        @if(session('status'))
            <article class="toast toast--success" data-toast data-timeout="4800" role="status"><span class="toast__icon"><x-icon name="check" :size="17" /></span><div class="toast__content"><strong>Done</strong><p>{{ session('status') }}</p></div><button class="toast__close" type="button" data-toast-close aria-label="Dismiss notification"><x-icon name="close" :size="15" /></button><span class="toast__progress" aria-hidden="true"></span></article>
        @endif
        @if(session('warning'))
            <article class="toast toast--warning" data-toast data-timeout="7000" role="status"><span class="toast__icon"><x-icon name="help" :size="17" /></span><div class="toast__content"><strong>Attention</strong><p>{{ session('warning') }}</p></div><button class="toast__close" type="button" data-toast-close aria-label="Dismiss notification"><x-icon name="close" :size="15" /></button><span class="toast__progress" aria-hidden="true"></span></article>
        @endif
        @if(session('error'))
            <article class="toast toast--danger" data-toast data-timeout="9000" role="alert"><span class="toast__icon"><x-icon name="help" :size="17" /></span><div class="toast__content"><strong>Action failed</strong><p>{{ session('error') }}</p></div><button class="toast__close" type="button" data-toast-close aria-label="Dismiss notification"><x-icon name="close" :size="15" /></button><span class="toast__progress" aria-hidden="true"></span></article>
        @endif
        @if($errors->any())
            <article class="toast toast--danger" data-toast data-timeout="10000" role="alert"><span class="toast__icon"><x-icon name="help" :size="17" /></span><div class="toast__content"><strong>Please check the information</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div><button class="toast__close" type="button" data-toast-close aria-label="Dismiss notification"><x-icon name="close" :size="15" /></button><span class="toast__progress" aria-hidden="true"></span></article>
        @endif
    </div>
    <dialog class="modal-dialog confirmation-dialog" data-confirmation-dialog aria-labelledby="confirmation-title" aria-describedby="confirmation-message">
        <div class="modal-dialog__surface confirmation-dialog__surface">
            <header class="confirmation-dialog__header"><span class="confirmation-dialog__icon"><x-icon name="help" :size="22" /></span><div><span class="eyebrow">Please confirm</span><h2 id="confirmation-title" data-confirmation-title>Confirm action</h2></div><button class="icon-button" type="button" data-confirmation-cancel aria-label="Cancel and close"><x-icon name="close" /></button></header>
            <div class="confirmation-dialog__body"><p id="confirmation-message" data-confirmation-message></p><small>This action will only continue after you choose the confirmation button below.</small></div>
            <footer class="confirmation-dialog__footer"><button class="button button--secondary" type="button" data-confirmation-cancel>Cancel</button><button class="button" type="button" data-confirmation-accept>Confirm</button></footer>
        </div>
    </dialog>
    <x-help-assistant />
@endguest
@stack('scripts')
</body>
</html>
