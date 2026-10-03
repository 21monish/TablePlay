@extends('layouts.app')
@section('title','Licence')
@section('eyebrow','Restaurant configuration')
@section('page-title','Licence & subscription')

@section('content')
@php
    $status = data_get($entitlements, 'status', 'missing');
    $paired = (int) data_get($entitlements, 'usage.paired_tables', 0);
    $limit = data_get($entitlements, 'limits.paired_tables');
    $usagePercent = $limit === null ? ($paired > 0 ? 100 : 0) : ($limit > 0 ? min(100, (int) round($paired / $limit * 100)) : 0);
    $features = collect(data_get($entitlements, 'features', []));
    $enabledFeatures = $features->filter();
    $disabledFeatures = $features->reject();
    $date = fn ($value) => $value ? $value->format('d M Y, h:i A T') : 'No expiry';
@endphp

<div class="page-heading">
    <div><h2>Your TablePlay access</h2><p>Commercial subscription and offline verification are shown separately. All times use {{ config('app.timezone') }}.</p></div>
    <div class="page-heading__actions">
        <form method="post" action="{{ route('admin.license.sync') }}">@csrf<button class="button button--secondary" type="submit" @disabled(!$installation->activation_token)><x-icon name="update" :size="17" /> Synchronize now</button></form>
    </div>
</div>

<div class="stats-grid">
    <article class="stat-card"><span>Plan</span><strong>{{ data_get($entitlements,'plan.name','Not activated') }}</strong><small>{{ $entitlements['license_reference'] ?? 'No licence reference' }}</small></article>
    <article class="stat-card"><span>Subscription status</span><strong>{{ str($status)->replace('_',' ')->title() }}</strong><small>{{ data_get($entitlements, 'signature_valid') ? 'Digital signature verified' : 'Signature not verified' }} · {{ str(data_get($entitlements,'source','none'))->title() }}</small></article>
    <article class="stat-card"><span>Days remaining</span><strong>{{ data_get($entitlements,'days_remaining') === null ? 'No expiry' : data_get($entitlements,'days_remaining') }}</strong><small>Calculated by the restaurant server</small></article>
    <article class="stat-card"><span>Customer tablets</span><strong>{{ $paired }} / {{ $limit ?? 'Unlimited' }}</strong><small>Current plan usage</small><div class="setup-progress" style="margin-top:10px"><i style="width:{{ $usagePercent }}%"></i></div></article>
</div>

@foreach($entitlements['warnings'] ?? [] as $warning)
<section class="card" style="margin-top:16px;border-color:{{ $warning['level']==='danger'?'#fecaca':'#fde68a' }}"><div class="card__body"><strong>{{ $warning['message'] }}</strong></div></section>
@endforeach

<div class="content-grid content-grid--2" style="margin-top:18px">
    <section class="card">
        <div class="card__header"><div><h2>Subscription dates</h2><p>Renewal and offline proof are independent</p></div><x-icon name="license" /></div>
        <div class="card__body stack">
            <div class="inline-meta"><strong>Starts</strong><span>{{ $date(data_get($entitlements,'starts_at')) }}</span></div>
            <div class="inline-meta"><strong>Subscription expires</strong><span>{{ $date(data_get($entitlements,'subscription_expires_at')) }}</span></div>
            <div class="inline-meta"><strong>Grace period ends</strong><span>{{ $date(data_get($entitlements,'grace_ends_at')) }}</span></div>
            <div class="inline-meta"><strong>Offline verification due</strong><span>{{ data_get($entitlements,'source') === 'offline' ? $date(data_get($entitlements,'offline_verification_due_at')) : 'Not applicable' }}</span></div>
            <div class="inline-meta"><strong>Last successful sync</strong><span>{{ $installation->last_sync_at?->format('d M Y, h:i A T') ?? 'Never' }}</span></div>
            @if($installation->last_sync_error)<div class="alert alert--danger" role="alert"><div><strong>Last synchronization failed</strong><div>{{ $installation->last_sync_error }}</div><small>Check internet access to TablePlay Cloud, then use Synchronize now. For an offline server, import a fresh .tpl licence.</small></div></div>@endif
        </div>
    </section>

    <section class="card">
        <div class="card__header"><div><h2>Plan features</h2><p>Enforced by this restaurant server</p></div><x-icon name="check" /></div>
        <div class="card__body stack">
            @forelse($enabledFeatures as $feature=>$enabled)<div class="inline-meta"><x-icon name="check" :size="16" /><strong>{{ str($feature)->replace('_',' ')->title() }}</strong><x-status value="included" /></div>@empty<p class="muted">Activate a licence to see included features.</p>@endforelse
            @foreach($disabledFeatures as $feature=>$enabled)<div class="inline-meta"><x-icon name="lock" :size="16" /><span>{{ str($feature)->replace('_',' ')->title() }}</span><x-status value="unavailable" /></div>@endforeach
            <div class="modal-note"><x-icon name="device" :size="16" /><span>Paired table limit: <strong>{{ $limit ?? 'Unlimited' }}</strong></span></div>
        </div>
    </section>
</div>

<div class="content-grid content-grid--2" style="margin-top:18px">
    <section class="card"><div class="card__header"><div><h2>Online activation</h2><p>Use the key supplied from TablePlay Cloud</p></div><x-icon name="license" /></div><form class="card__body" method="post" action="{{ route('admin.license.activate') }}">@csrf<div class="form-grid"><label class="field field--full">TablePlay Cloud URL<input type="url" name="cloud_url" value="{{ old('cloud_url',$installation->cloud_url ?: config('tableplay.cloud_url')) }}" placeholder="https://cloud.tableplay.example" required></label><label class="field field--full">Licence key<input name="license_key" autocomplete="off" placeholder="TP-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX" required></label></div><div class="form-actions"><button class="button" type="submit">Activate and verify</button></div></form></section>
    <section class="card">
        <div class="card__header"><div><h2>Offline activation</h2><p>Use signed files when this server has no internet. Refresh verification at least every {{ max(1, (int) config('tableplay.offline_license_lease_days', 30)) }} days.</p></div><x-icon name="download" /></div>
        <div class="card__body"><div class="stack" style="gap:16px">
            <div><strong>1. Export activation request</strong><p class="muted" style="margin:5px 0 10px">Move the signed <code>.tpr</code> file to the TablePlay Cloud computer. It contains installation identity only.</p><form method="post" action="{{ route('admin.license.request') }}">@csrf<button class="button button--secondary" type="submit"><x-icon name="download" :size="17" /> Create or download request</button></form></div>
            <form method="post" action="{{ route('admin.license.import') }}" enctype="multipart/form-data">@csrf<strong>2. Import signed licence</strong><label class="field" style="margin-top:10px">TablePlay licence file<input type="file" name="license_file" accept=".tpl,.json,application/json" required><small>Import the <code>.tpl</code> returned for installation {{ $installation->installation_uuid }}.</small></label><div class="form-actions"><button class="button" type="submit">Verify and activate licence</button></div></form>
            @if($offlineRequests->isNotEmpty())<div><strong>Recent requests</strong><div style="margin-top:8px;display:grid;gap:7px">@foreach($offlineRequests as $activationRequest)<div class="inline-meta"><code>{{ str($activationRequest->request_id)->limit(18) }}</code><x-status :value="$activationRequest->status" /><small>{{ $activationRequest->created_at->diffForHumans() }}</small><a href="{{ route('admin.license.request.download', $activationRequest) }}">Download</a></div>@endforeach</div></div>@endif
        </div></div>
    </section>
</div>

<section class="card" style="margin-top:18px"><div class="card__header"><div><h2>Activation, renewal and synchronization history</h2><p>No passwords, customer records, orders or payments are transmitted.</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Time</th><th>Event</th><th>Status</th><th>Detail</th></tr></thead><tbody>@forelse($syncLogs as $log)<tr><td>{{ $log->created_at->format('d M Y H:i') }}</td><td>{{ str($log->event)->replace('.',' ')->title() }}</td><td><x-status :value="$log->status" /></td><td>{{ $log->error ?: 'Completed' }}</td></tr>@empty<tr><td colspan="4"><x-empty-state icon="license" title="No licence activity yet" /></td></tr>@endforelse</tbody></table></div></section>
@endsection
