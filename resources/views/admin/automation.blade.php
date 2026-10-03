@extends('layouts.app')
@section('title', 'Automation center')
@section('eyebrow', 'Configuration')
@section('page-title', 'Automation center')

@section('content')
<div class="page-heading"><div><h2>Reliable daily operations</h2><p>Schedule local backups, close overdue game timers, and surface delays before they affect guests.</p></div><div class="page-heading__actions"><form method="post" action="{{ route('admin.automation.run') }}">@csrf<button class="button button--secondary" type="submit"><x-icon name="refresh" :size="17" /> Run maintenance</button></form><form method="post" action="{{ route('admin.automation.backup') }}">@csrf<button class="button" type="submit" @disabled(!$backup['available'])><x-icon name="database" :size="17" /> Back up now</button></form></div></div>

<div class="automation-hero">
    <section class="card card--accent automation-status"><span class="automation-status__icon {{ $scheduler['running'] ? 'is-live' : '' }}"><x-icon name="automation" :size="28" /></span><div><span class="eyebrow">Windows scheduler</span><h2>{{ !$scheduler['enabled'] ? 'Paused by Admin' : ($scheduler['running'] ? 'Automation is live' : 'Waiting for scheduler') }}</h2><p>{{ $scheduler['last_seen_at'] ? 'Last check '.\Illuminate\Support\Carbon::parse($scheduler['last_seen_at'])->diffForHumans() : 'The scheduler has not checked in yet. Restart the TablePlay Windows service after upgrading.' }}</p></div><x-status :value="$scheduler['running'] ? 'healthy' : ($scheduler['enabled'] ? 'warning' : 'stopped')" /></section>
    <section class="card automation-backup"><span class="stat-card__icon"><x-icon name="database" /></span><div><strong>{{ $backup['last_backup_at'] ? \Illuminate\Support\Carbon::parse($backup['last_backup_at'])->diffForHumans() : 'Not yet' }}</strong><small>Last automatic or manual backup</small><p>{{ $backup['message'] }}</p></div></section>
</div>

<div class="automation-alert-grid">
    @foreach([
        ['expired_game_sessions','clock','Overdue timers','Closed automatically on the next check'],
        ['delayed_orders','order','Delayed orders','Pending beyond the alert threshold'],
        ['delayed_requests','bell','Delayed requests','Guest requests waiting too long'],
        ['offline_devices','device','Offline tablets','Active devices outside the heartbeat window'],
    ] as [$key,$icon,$label,$note])
    <article class="card automation-alert {{ $alerts[$key] > 0 ? 'has-alert' : '' }}"><span class="stat-card__icon"><x-icon :name="$icon" /></span><div><strong>{{ $alerts[$key] }}</strong><span>{{ $label }}</span><small>{{ $note }}</small></div></article>
    @endforeach
</div>

<section class="card" style="margin-top:18px"><div class="card__header"><div><h2>Backup vault</h2><p>{{ $backup['count'] }} local backup file(s) &middot; {{ $backup['total_bytes'] < 1048576 ? round($backup['total_bytes']/1024,1).' KB' : round($backup['total_bytes']/1048576,1).' MB' }} total</p></div><x-icon name="storage" /></div><div class="card__body automation-vault">
    @forelse($backup['files'] as $file)
    <article><span class="automation-run__icon"><x-icon name="database" :size="17" /></span><div><strong>{{ $file['filename'] }}</strong><small>{{ str($file['type'])->title() }} &middot; {{ $file['size_bytes'] < 1048576 ? round($file['size_bytes']/1024,1).' KB' : round($file['size_bytes']/1048576,1).' MB' }} &middot; {{ \Illuminate\Support\Carbon::parse($file['created_at'])->diffForHumans() }}</small></div><a class="button button--secondary button--small" href="{{ route('admin.automation.backups.download',$file['filename']) }}"><x-icon name="update" :size="14" /> Download</a></article>
    @empty
    <div class="empty-state"><span class="empty-state__icon"><x-icon name="database" /></span><strong>No local backups yet</strong><p>Create the first verified backup after installing this release.</p></div>
    @endforelse
</div><div class="card__footer"><span class="muted">Downloads require an authenticated Admin account. Restore remains protected inside Server Manager.</span></div></section>

<div class="content-grid content-grid--2" style="margin-top:18px">
    <form class="card" method="post" action="{{ route('admin.automation.update') }}">@csrf @method('PUT')
        <div class="card__header"><div><h2>Automation rules</h2><p>Safe defaults for this restaurant</p></div><x-icon name="settings" /></div>
        <div class="card__body stack">
            <label class="automation-toggle"><span><strong>Scheduled maintenance</strong><small>Check game timers and operational delays every minute.</small></span><input type="hidden" name="automation_enabled" value="0"><input type="checkbox" name="automation_enabled" value="1" @checked(old('automation_enabled',$settings?->automation_enabled ?? true))></label>
            <label class="automation-toggle"><span><strong>Daily database backup</strong><small>Create a private local SQL backup at the selected time.</small></span><input type="hidden" name="auto_backup_enabled" value="0"><input type="checkbox" name="auto_backup_enabled" value="1" @checked(old('auto_backup_enabled',$settings?->auto_backup_enabled ?? true))></label>
            <div class="form-grid">
                <label class="field">Backup time<input type="time" name="backup_time" value="{{ old('backup_time',$settings?->backup_time ?: '02:00') }}" required></label>
                <label class="field">Keep automatic backups<div class="input-group"><input type="number" name="backup_retention_days" min="1" max="365" value="{{ old('backup_retention_days',$settings?->backup_retention_days ?: 14) }}" required><span class="input-group__suffix">days</span></div></label>
                <label class="field">Pending-order alert<div class="input-group"><input type="number" name="pending_order_alert_minutes" min="1" max="120" value="{{ old('pending_order_alert_minutes',$settings?->pending_order_alert_minutes ?: 5) }}" required><span class="input-group__suffix">min</span></div></label>
                <label class="field">Guest-request alert<div class="input-group"><input type="number" name="service_request_alert_minutes" min="1" max="120" value="{{ old('service_request_alert_minutes',$settings?->service_request_alert_minutes ?: 3) }}" required><span class="input-group__suffix">min</span></div></label>
            </div>
        </div>
        <div class="card__footer"><span class="muted">Manual Server Manager backups are never removed by retention.</span><button class="button button--accent" type="submit"><x-icon name="check" :size="17" /> Save automation</button></div>
    </form>

    <section class="card"><div class="card__header"><div><h2>Recent activity</h2><p>Manual actions and database backup history</p></div><x-icon name="clock" /></div><div class="card__body automation-runs">
        @forelse($recent_runs as $run)
        <article><span class="automation-run__icon"><x-icon :name="$run->type === 'backup' ? 'database' : 'automation'" :size="17" /></span><div><strong>{{ str($run->type)->replace('_',' ')->title() }}</strong><small>{{ $run->user?->name ?: 'Windows scheduler' }} &middot; {{ $run->started_at->diffForHumans() }}</small>@if($run->error)<p>{{ $run->error }}</p>@elseif($run->summary)<p>{{ collect($run->summary)->map(fn($value,$key)=>str($key)->replace('_',' ')->title().': '.$value)->take(2)->implode(' · ') }}</p>@endif</div><x-status :value="$run->status" /></article>
        @empty
        <div class="empty-state"><span class="empty-state__icon"><x-icon name="automation" /></span><strong>No automation history yet</strong><p>Run maintenance or create a backup to verify the workflow.</p></div>
        @endforelse
    </div></section>
</div>
@endsection
