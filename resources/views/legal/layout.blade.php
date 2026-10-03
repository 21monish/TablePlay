<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title') · TablePlay</title>
    <link rel="icon" href="{{ route('brand.favicon') }}">
    @vite(['resources/css/app.css', 'resources/css/portal.css'])
    <style>
        .legal-page{min-height:100vh;background:#f5f7f5;color:#17231f}.legal-header{background:#0e302a;color:#fff}.legal-header__inner,.legal-main,.legal-footer__inner{width:min(920px,calc(100% - 40px));margin:auto}.legal-header__inner{min-height:92px;display:flex;align-items:center;justify-content:space-between;gap:24px}.legal-header a{color:inherit;text-decoration:none}.legal-back{display:inline-flex;align-items:center;gap:8px;font-weight:700;color:#d9e7e2!important}.legal-main{padding:54px 0 70px}.legal-card{background:#fff;border:1px solid #dce4e0;border-radius:24px;padding:clamp(28px,5vw,58px);box-shadow:0 18px 55px rgba(14,48,42,.08)}.legal-eyebrow{margin:0 0 10px;color:#d95e32;font-size:.78rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.legal-card h1{margin:0;color:#10241e;font-size:clamp(2.15rem,5vw,3.4rem);line-height:1.04}.legal-updated{display:block;margin-top:12px;color:#6a7873}.legal-intro{margin:28px 0 34px;padding:20px 22px;border-radius:16px;background:#eef6f2;color:#25443a;font-size:1.08rem;line-height:1.7}.legal-section{padding:27px 0;border-top:1px solid #e3e8e5}.legal-section h2{margin:0 0 12px;color:#16372e;font-size:1.35rem}.legal-section p,.legal-section li{color:#4b5d57;line-height:1.75}.legal-section p{margin:0 0 12px}.legal-section ul{margin:0;padding-left:22px}.legal-section a{color:#b84622;font-weight:700}.legal-note{padding:18px 20px;border-left:4px solid #ef6a3a;background:#fff5ef;border-radius:10px}.legal-footer{border-top:1px solid #d9e2de;background:#fff}.legal-footer__inner{padding:28px 0;display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;color:#60706a}.legal-footer a{color:#21483d;font-weight:700;text-decoration:none}.legal-footer nav{display:flex;gap:18px}@media(max-width:640px){.legal-header__inner{min-height:78px}.legal-main{padding:28px 0 45px}.legal-header .brand-lockup__copy{display:none}.legal-card{border-radius:18px}.legal-footer__inner{display:block}.legal-footer nav{margin-top:13px}}
    </style>
</head>
<body class="legal-page">
<header class="legal-header">
    <div class="legal-header__inner">
        <a href="{{ route('portal') }}" aria-label="TablePlay home"><x-brand light /></a>
        <a class="legal-back" href="{{ route('portal') }}">← Back to TablePlay</a>
    </div>
</header>
<main class="legal-main">
    <article class="legal-card">
        <p class="legal-eyebrow">TablePlay legal</p>
        <h1>@yield('heading')</h1>
        <small class="legal-updated">Effective: 3 October 2026</small>
        @yield('content')
    </article>
</main>
<footer class="legal-footer">
    <div class="legal-footer__inner">
        <span>&copy; {{ now()->year }} TablePlay</span>
        <nav aria-label="Legal links"><a href="{{ route('privacy') }}">Privacy Policy</a><a href="{{ route('terms') }}">Terms of Service</a></nav>
    </div>
</footer>
</body>
</html>
