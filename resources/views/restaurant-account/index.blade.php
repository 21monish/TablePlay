@extends('layouts.app')
@section('title', 'Restaurant account')
@section('eyebrow', 'Restaurant onboarding')
@section('page-title', $restaurant->name)

@section('content')
<div class="page-heading"><div><h2>Your TablePlay trial</h2><p>Email verified. Complete the steps below to activate your restaurant server.</p></div><span class="status-badge status-badge--active"><i></i>{{ ucfirst($subscription->status) }}</span></div>

<section class="stats-grid" style="margin-bottom:18px">
    <article class="stat-card"><span>Plan</span><strong>{{ $subscription->plan->name }}</strong><small>{{ $subscription->plan->trial_days }} days</small></article>
    <article class="stat-card"><span>Trial ends</span><strong>{{ $subscription->expires_at?->format('d M Y') }}</strong><small>{{ max(0, now()->startOfDay()->diffInDays($subscription->expires_at, false)) }} days remaining</small></article>
    <article class="stat-card"><span>Customer tablets</span><strong>Up to {{ $subscription->plan->max_paired_tables }}</strong><small>Paired-table allowance</small></article>
    <article class="stat-card"><span>Server installations</span><strong>{{ $subscription->installations->where('status', 'active')->count() }} / {{ $subscription->max_installations }}</strong><small>Protected activation</small></article>
</section>

<section class="card" style="margin-bottom:18px">
    <div class="card__header"><div><h2>Verified downloads</h2><p>Only current packages published by TablePlay appear here. Compare the SHA-256 checksum before installation.</p></div><x-icon name="download" /></div>
    <div class="card__body download-catalog">
        <article class="download-card">
            <div class="download-card__heading"><span class="download-card__icon"><x-icon name="setup" /></span><div><strong>TablePlay Setup</strong><small>Restaurant server · Windows x64</small></div></div>
            @if($installer['available'])
                <dl><div><dt>Version</dt><dd>{{ $installer['version'] }}</dd></div><div><dt>Size</dt><dd>{{ number_format($installer['file_size'] / 1048576, 1) }} MB</dd></div></dl>
                <code class="download-checksum" title="{{ $installer['sha256'] }}">SHA-256 {{ $installer['sha256'] }}</code>
                <a class="button button--accent" href="{{ route('account.installer.download') }}"><x-icon name="download" :size="16" /> Download Setup</a>
            @else
                <span class="status-badge status-badge--warning"><i></i>Not yet published</span>
                <p class="muted">The installer will appear after its verified package and checksum are published.</p>
            @endif
        </article>

        @foreach($appDownloads as $download)
            <article class="download-card">
                <div class="download-card__heading"><span class="download-card__icon"><x-icon name="device" /></span><div><strong>{{ $download['label'] }}</strong><small>{{ ucfirst($download['release']?->platform ?? 'application') }} package</small></div></div>
                @if($download['available'])
                    <dl><div><dt>Version</dt><dd>{{ $download['release']->version }}</dd></div><div><dt>Size</dt><dd>{{ number_format($download['release']->file_size / 1048576, 1) }} MB</dd></div></dl>
                    <code class="download-checksum" title="{{ $download['release']->sha256 }}">SHA-256 {{ $download['release']->sha256 }}</code>
                    <a class="button button--secondary" href="{{ $download['url'] }}"><x-icon name="download" :size="16" /> Download</a>
                @else
                    <span class="status-badge status-badge--warning"><i></i>Not yet published</span>
                    <p class="muted">No verified stable package is currently available.</p>
                @endif
            </article>
        @endforeach
    </div>
</section>

@if(session('issued_license_key'))
<section class="card" style="margin-bottom:18px;border-color:#86efac">
    <div class="card__header"><div><h2>One-time activation key</h2><p>Copy this key now. TablePlay stores only its secure hash.</p></div></div>
    <div class="card__body"><code class="activation-key">{{ session('issued_license_key') }}</code><p class="muted">Enter this key in TablePlay Setup. Generating another key disables any unused previous key.</p></div>
</section>
@endif

<div class="content-grid content-grid--2">
    <section class="card">
        <div class="card__header"><div><h2>Activate the restaurant server</h2><p>One key connects this trial to one protected installation.</p></div><x-icon name="license" /></div>
        <div class="card__body"><ol class="account-steps"><li><strong>Download TablePlay Setup</strong><span>Use only the verified package displayed above.</span></li><li><strong>Generate an activation key</strong><span>Copy it immediately and keep it private.</span></li><li><strong>Run the installer as administrator</strong><span>Enter the key when the licence step appears. A retry is safe after a timeout.</span></li><li><strong>Connect restaurant devices</strong><span>Use the local server URL and QR pairing shown by Setup.</span></li><li><strong>Complete a test order</strong><span>Confirm Counter, Kitchen, Waiter and Customer Tablet updates.</span></li></ol><form method="post" action="{{ route('account.activation-key') }}">@csrf<button class="button button--accent" type="submit">Generate activation key</button></form></div>
    </section>
    <section class="card">
        <div class="card__header"><div><h2>Requirements and account</h2><p>Prepare the main restaurant laptop</p></div><x-icon name="health" /></div>
        <div class="card__body"><ul class="requirements-list"><li>Windows 10 or Windows 11, 64-bit</li><li>Administrator access for installation</li><li>At least 5 GB free disk space</li><li>Private restaurant Wi-Fi/LAN for Staff and Customer devices</li><li>Internet for initial online activation; local restaurant operation remains available afterward</li></ul><dl class="account-details"><div><dt>Restaurant</dt><dd>{{ $restaurant->name }}</dd></div><div><dt>Owner</dt><dd>{{ $restaurant->owner_name }}</dd></div><div><dt>Email</dt><dd>{{ $restaurant->owner_email }}</dd></div><div><dt>Mobile</dt><dd>{{ $restaurant->owner_phone }}</dd></div><div><dt>City</dt><dd>{{ $restaurant->city }}</dd></div><div><dt>Licence</dt><dd>{{ $subscription->license_reference }}</dd></div></dl></div>
    </section>
</div>
@endsection
