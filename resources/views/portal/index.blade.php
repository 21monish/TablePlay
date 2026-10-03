<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#143c35">
    <meta name="description" content="TablePlay brings local-network ordering, staff operations, kitchen workflow, billing and table games together.">
    <title>TablePlay Portal &middot; Restaurant ordering, operations and play</title>
    <link rel="icon" href="{{ route('brand.favicon',['v'=>$appSettings?->updated_at?->timestamp]) }}">
    @vite(['resources/css/app.css', 'resources/css/portal.css'])
</head>
<body class="portal-page" style="--brand-accent: {{ $appSettings?->brand_color ?: '#ef6a3a' }};--brand-deep: {{ $appSettings?->secondary_color ?: '#123c35' }}">
<a class="portal-skip" href="#main-content">Skip to content</a>

<header class="portal-header" data-portal-header>
    <div class="portal-shell portal-header__inner">
        <a class="portal-brand" href="{{ route('portal') }}" aria-label="TablePlay Portal home"><x-brand /></a>
        <button class="portal-menu-button" type="button" data-portal-menu-toggle aria-expanded="false" aria-controls="portal-navigation">
            <span></span><span></span><span></span><span class="sr-only">Open navigation</span>
        </button>
        <nav class="portal-nav" id="portal-navigation" data-portal-menu aria-label="Portal navigation">
            <a href="#features">Features</a>
            <a href="#applications">Applications</a>
            <a href="#installation">Installation</a>
            <a href="#support">Support</a>
            @auth
                <a class="portal-nav__login" href="/{{ auth()->user()->role->name }}">Open workspace <x-icon name="arrow" :size="15" /></a>
            @else
                <a class="portal-nav__login" href="{{ route('login') }}">Staff sign in <x-icon name="arrow" :size="15" /></a>
            @endauth
        </nav>
    </div>
</header>

<main id="main-content">
    <section class="portal-hero">
        <div class="portal-hero__glow portal-hero__glow--one"></div>
        <div class="portal-hero__glow portal-hero__glow--two"></div>
        <div class="portal-shell portal-hero__grid">
            <div class="portal-hero__copy" data-portal-reveal>
                <span class="portal-kicker"><i></i> Local-first restaurant experience</span>
                <h1>Run the restaurant.<br><span>Delight every table.</span></h1>
                <p>TablePlay connects ordering, staff operations, kitchen updates, billing and table games on one private restaurant network—without depending on the internet.</p>
                <div class="portal-hero__actions">
                    <a class="portal-button portal-button--primary" href="#applications">Get the applications <x-icon name="arrow" :size="17" /></a>
                    <a class="portal-button portal-button--ghost" href="#how-it-works">See how it works</a>
                </div>
                <div class="portal-hero__trust">
                    <span><x-icon name="check" :size="16" /> Offline ready</span>
                    <span><x-icon name="check" :size="16" /> Real-time updates</span>
                    <span><x-icon name="check" :size="16" /> Role-based access</span>
                </div>
            </div>

            <div class="portal-hero__visual" data-portal-reveal>
                <div class="portal-window">
                    <div class="portal-window__bar"><span></span><span></span><span></span><small>TablePlay Staff</small></div>
                    <div class="portal-window__body">
                        <aside class="portal-preview-nav">
                            <img src="{{ $appSettings?->restaurant_logo_url ?: $appSettings?->app_logo_url ?: '/brand/tableplay-mark.svg' }}" alt="" width="34" height="34">
                            <i class="is-active"></i><i></i><i></i><i></i><i></i>
                        </aside>
                        <div class="portal-preview-dashboard">
                            <div class="portal-preview-dashboard__top"><span><small>LIVE OPERATIONS</small><strong>Good evening, Counter</strong></span><i></i></div>
                            <div class="portal-preview-stats"><span><small>Open tables</small><strong>08</strong></span><span><small>New orders</small><strong>12</strong></span><span><small>Ready</small><strong>05</strong></span></div>
                            <div class="portal-preview-content">
                                <div class="portal-preview-orders">
                                    <strong>Active orders</strong>
                                    <span><i>T01</i><b>Order #1042</b><em>Preparing</em></span>
                                    <span><i>T04</i><b>Order #1043</b><em class="is-ready">Ready</em></span>
                                    <span><i>T08</i><b>Order #1044</b><em>Accepted</em></span>
                                </div>
                                <div class="portal-preview-tables"><strong>Tables</strong><span class="is-busy">1</span><span>2</span><span class="is-ready">3</span><span>4</span></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="portal-live-card"><span class="portal-live-card__pulse"></span><span><strong>Restaurant online</strong><small>Ordering and Reverb connected</small></span></div>
            </div>
        </div>
    </section>

    <section class="portal-proof" aria-label="TablePlay capabilities">
        <div class="portal-shell portal-proof__grid">
            <span><x-icon name="database" :size="22" /><b>Private server</b><small>Restaurant-owned data</small></span>
            <span><x-icon name="order" :size="22" /><b>Live ordering</b><small>Table to kitchen</small></span>
            <span><x-icon name="game" :size="22" /><b>Game access</b><small>Controlled by orders</small></span>
            <span><x-icon name="report" :size="22" /><b>Clear reporting</b><small>Sales and activity</small></span>
        </div>
    </section>

    <section class="portal-section" id="features">
        <div class="portal-shell">
            <div class="portal-section__heading" data-portal-reveal>
                <span class="portal-kicker">Everything connected</span>
                <h2>One calm workflow from table to counter</h2>
                <p>Each role gets the tools it needs while every action stays connected to the same live restaurant state.</p>
            </div>
            <div class="portal-feature-grid">
                <article class="portal-feature" data-portal-reveal><span><x-icon name="device" :size="24" /></span><h3>Table ordering</h3><p>Customers browse the menu, add instructions, order and follow progress from the table device.</p><small>Customer experience</small></article>
                <article class="portal-feature" data-portal-reveal><span><x-icon name="counter" :size="24" /></span><h3>Counter control</h3><p>Confirm orders, manage tables, generate bills, receive cash and control game time.</p><small>Front-of-house</small></article>
                <article class="portal-feature" data-portal-reveal><span><x-icon name="kitchen" :size="24" /></span><h3>Kitchen display</h3><p>See incoming items and instructions, then progress orders through preparing, ready and served.</p><small>Kitchen workflow</small></article>
                <article class="portal-feature" data-portal-reveal><span><x-icon name="bell" :size="24" /></span><h3>Waiter requests</h3><p>Handle waiter calls, water and bill requests without losing the table context.</p><small>Service team</small></article>
                <article class="portal-feature" data-portal-reveal><span><x-icon name="game" :size="24" /></span><h3>Order-unlocked games</h3><p>Games unlock only after confirmation and lock automatically when time or billing ends.</p><small>TablePlay engagement</small></article>
                <article class="portal-feature" data-portal-reveal><span><x-icon name="health" :size="24" /></span><h3>System health</h3><p>Administrators monitor services, devices, application versions and operational settings.</p><small>Owner confidence</small></article>
            </div>
        </div>
    </section>

    <section class="portal-section portal-section--soft" id="applications">
        <div class="portal-shell">
            <div class="portal-section__heading portal-section__heading--left" data-portal-reveal>
                <span class="portal-kicker">Applications</span>
                <h2>Two applications. One restaurant.</h2>
                <p>Install the role-based Staff application on Windows and the Customer application on each Android table device.</p>
            </div>

            <div class="portal-app-grid">
                <article class="portal-app-card" data-portal-reveal>
                    <div class="portal-app-card__copy">
                        <span class="portal-app-card__icon"><x-icon name="counter" :size="28" /></span>
                        <span class="portal-kicker">Windows desktop</span>
                        <h3>Staff Desktop</h3>
                        <p>One application for Admin, Counter, Kitchen and Waiter. The correct workspace opens automatically after role-based login.</p>
                        <div class="portal-role-list"><span>Admin</span><span>Counter</span><span>Kitchen</span><span>Waiter</span></div>
                        @if($staffRelease)
                            <div class="portal-release-meta"><span>Version {{ $staffRelease->version }}</span><span>{{ number_format($staffRelease->file_size / 1048576, 1) }} MB</span><span title="{{ $staffRelease->sha256 }}">SHA {{ str($staffRelease->sha256)->limit(12) }}</span></div>
                        @endif
                        @if($staffAvailable)
                            <a class="portal-button portal-button--primary" href="{{ route('api.app-updates.download', ['target' => 'staff-windows'], false) }}">Download Staff Desktop <x-icon name="update" :size="17" /></a>
                        @else
                            <span class="portal-button portal-button--disabled" aria-disabled="true">Not yet published</span>
                        @endif
                    </div>
                    <div class="portal-desktop-art" aria-hidden="true"><span class="portal-desktop-art__screen"><i></i><b></b><b></b><b></b><em></em></span><span class="portal-desktop-art__stand"></span></div>
                </article>

                <article class="portal-app-card portal-app-card--customer" data-portal-reveal>
                    <div class="portal-app-card__copy">
                        <span class="portal-app-card__icon"><x-icon name="device" :size="28" /></span>
                        <span class="portal-kicker">Android table app</span>
                        <h3>Customer Table App</h3>
                        <p>A focused table experience for menu browsing, orders, live status, service requests, billing and unlocked games.</p>
                        <div class="portal-role-list"><span>Menu</span><span>Orders</span><span>Requests</span><span>Games</span></div>
                        @if($customerRelease)
                            <div class="portal-release-meta"><span>Version {{ $customerRelease->version }}</span><span>{{ number_format($customerRelease->file_size / 1048576, 1) }} MB</span><span title="{{ $customerRelease->sha256 }}">SHA {{ str($customerRelease->sha256)->limit(12) }}</span></div>
                        @endif
                        @if($customerAvailable)
                            <a class="portal-button portal-button--light" href="{{ route('api.app-updates.download', ['target' => 'customer-android'], false) }}">Download Customer APK <x-icon name="update" :size="17" /></a>
                        @else
                            <span class="portal-button portal-button--disabled portal-button--disabled-light" aria-disabled="true">Not yet published</span>
                        @endif
                    </div>
                    <div class="portal-phone-art" aria-hidden="true"><span class="portal-phone-art__speaker"></span><div><small>TABLE 04</small><strong>What would you<br>like today?</strong><i></i><i></i><i></i><b>View cart · 3</b></div></div>
                </article>
            </div>
            <p class="portal-download-note"><x-icon name="help" :size="16" /> Android asks for permission before installing an APK outside the Play Store. Only install packages published by this TablePlay server.</p>
        </div>
    </section>

    <section class="portal-section" id="how-it-works">
        <div class="portal-shell">
            <div class="portal-section__heading" data-portal-reveal>
                <span class="portal-kicker">A complete service loop</span>
                <h2>From appetite to a closed table</h2>
            </div>
            <div class="portal-flow">
                <article data-portal-reveal><span>01</span><x-icon name="menu" :size="25" /><h3>Customer orders</h3><p>The table app sends items and instructions to the local server.</p></article>
                <article data-portal-reveal><span>02</span><x-icon name="counter" :size="25" /><h3>Counter confirms</h3><p>The order enters operations and game access starts for that table.</p></article>
                <article data-portal-reveal><span>03</span><x-icon name="kitchen" :size="25" /><h3>Kitchen prepares</h3><p>Live statuses keep staff and customers synchronized.</p></article>
                <article data-portal-reveal><span>04</span><x-icon name="receipt" :size="25" /><h3>Bill closes</h3><p>Payment completes the visit and locks the table session safely.</p></article>
            </div>
        </div>
    </section>

    <section class="portal-section portal-install" id="installation">
        <div class="portal-shell portal-install__grid">
            <div class="portal-install__copy" data-portal-reveal>
                <span class="portal-kicker portal-kicker--light">Simple local installation</span>
                <h2>Set up the restaurant without command lines</h2>
                <p>The TablePlay server installer prepares the private database and services once on the main laptop. Staff and table devices then connect through the same restaurant Wi-Fi.</p>
                <div class="portal-server-address"><span>Current portal address</span><strong>{{ $serverAddress }}</strong><small>Use the laptop’s network address from phones and tablets—not localhost.</small></div>
            </div>
            <ol class="portal-install__steps">
                <li data-portal-reveal><span>1</span><div><strong>Install the server</strong><p>Run TablePlay-Setup.exe once on the main Windows laptop.</p></div></li>
                <li data-portal-reveal><span>2</span><div><strong>Open the Portal</strong><p>Confirm the restaurant network address and sign in as Admin.</p></div></li>
                <li data-portal-reveal><span>3</span><div><strong>Install applications</strong><p>Download Staff Desktop and the Customer APK from this page.</p></div></li>
                <li data-portal-reveal><span>4</span><div><strong>Pair the tables</strong><p>Create tables in Admin, register each device and assign it once.</p></div></li>
                <li data-portal-reveal><span>5</span><div><strong>Run a test order</strong><p>Confirm ordering, kitchen status, billing and game access before service.</p></div></li>
            </ol>
        </div>
    </section>

    <section class="portal-section" id="support">
        <div class="portal-shell portal-support">
            <div class="portal-support__copy" data-portal-reveal>
                <span class="portal-kicker">Help and contact</span>
                <h2>Guidance stays close to the workflow</h2>
                <p>Staff can use the built-in TablePlay Guide after login. Administrators can also review system health, application versions and restaurant configuration from one workspace.</p>
                <div class="portal-support__actions">
                    <a class="portal-button portal-button--primary" href="{{ route('login') }}">Open staff login <x-icon name="arrow" :size="17" /></a>
                    <a class="portal-button portal-button--ghost" href="#installation">Installation guide</a>
                </div>
            </div>
            <div class="portal-contact-card" data-portal-reveal>
                <span class="portal-contact-card__icon"><x-icon name="chat" :size="25" /></span>
                <h3>{{ $appSettings?->restaurant_name ?: 'TablePlay Restaurant' }}</h3>
                @if($appSettings?->address)<p>{{ $appSettings->address }}</p>@else<p>Add the restaurant address and support details from Admin settings.</p>@endif
                <div>
                    @if($appSettings?->phone)<a href="tel:{{ preg_replace('/[^+0-9]/', '', $appSettings->phone) }}"><small>Phone</small><strong>{{ $appSettings->phone }}</strong></a>@endif
                    @if($appSettings?->email)<a href="mailto:{{ $appSettings->email }}"><small>Email</small><strong>{{ $appSettings->email }}</strong></a>@endif
                    <span><small>Local server</small><strong>{{ $serverAddress }}</strong></span>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="portal-footer">
    <div class="portal-shell portal-footer__inner">
        <x-brand light />
        <p>Local ordering, restaurant operations and table entertainment.</p>
        <div><a href="#features">Features</a><a href="#applications">Applications</a><a href="{{ route('privacy') }}">Privacy</a><a href="{{ route('terms') }}">Terms</a><a href="{{ route('login') }}">Staff sign in</a></div>
        <small>&copy; {{ now()->year }} TablePlay. Built for private restaurant networks.</small>
    </div>
</footer>

<script>
(() => {
    const toggle = document.querySelector('[data-portal-menu-toggle]');
    const menu = document.querySelector('[data-portal-menu]');
    const closeMenu = () => {
        toggle?.setAttribute('aria-expanded', 'false');
        menu?.classList.remove('is-open');
        document.body.classList.remove('portal-menu-open');
    };
    toggle?.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(open));
        menu?.classList.toggle('is-open', open);
        document.body.classList.toggle('portal-menu-open', open);
    });
    menu?.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
    document.addEventListener('keydown', event => event.key === 'Escape' && closeMenu());

    const revealItems = document.querySelectorAll('[data-portal-reveal]');
    if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        revealItems.forEach(item => item.classList.add('is-visible'));
        return;
    }
    const observer = new IntersectionObserver(entries => entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        }
    }), { threshold: .12 });
    revealItems.forEach(item => observer.observe(item));
})();
</script>
</body>
</html>
