@extends('layouts.app')
@section('title','App updates')
@section('page-title','App updates')
@section('eyebrow','Local release management')
@section('content')
@php
    $targetLabels = [
        'staff-android' => 'Staff Android',
        'customer-android' => 'Customer Android',
        'staff-windows' => 'Staff Windows',
    ];
@endphp

<div class="page-heading">
    <div>
        <h2>Local application delivery</h2>
        <p>Publish verified packages to staff and customer devices on the restaurant network. No internet connection is required.</p>
    </div>
    <div class="page-heading__actions"><a class="button button--secondary" href="{{ route('admin.system') }}"><x-icon name="health" :size="17" /> System health</a></div>
</div>

<section class="stats-grid">
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="update" /></span><span class="stat-card__meta">Catalog</span></div><div class="stat-card__value">{{ $releases->count() }}</div><div class="stat-card__label">Uploaded releases</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="check" /></span><span class="stat-card__meta">Live</span></div><div class="stat-card__value">{{ $releases->where('is_published',true)->count() }}</div><div class="stat-card__label">Published targets</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="device" /></span><span class="stat-card__meta">Reporting</span></div><div class="stat-card__value">{{ $installations->count() }}</div><div class="stat-card__label">Known installations</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="help" /></span><span class="stat-card__meta">Action</span></div><div class="stat-card__value">{{ $installations->where('update_available',true)->count() }}</div><div class="stat-card__label">Devices needing updates</div></article>
</section>

<div class="dashboard-grid dashboard-grid--balanced" style="margin-top:18px">
    <section class="card">
        <div class="card__header"><div><h2>Publish a package</h2><p>Checksum and file size are calculated automatically</p></div><span class="stat-card__icon"><x-icon name="update" /></span></div>
        <form class="card__body" method="post" action="{{ route('admin.app-updates.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="form-grid">
                <label class="field">Application target
                    <select name="target" required>
                        @foreach($targetLabels as $value => $label)<option value="{{ $value }}" @selected(old('target')===$value)>{{ $label }}</option>@endforeach
                    </select>
                </label>
                <label class="field">Release channel
                    <select name="channel" required><option value="stable" @selected(old('channel','stable')==='stable')>Stable — all restaurants</option><option value="pilot" @selected(old('channel')==='pilot')>Pilot — selected sites</option><option value="beta" @selected(old('channel')==='beta')>Beta — internal testing</option></select>
                </label>
                <label class="field">Staged rollout
                    <div class="input-group"><input type="number" name="rollout_percentage" value="{{ old('rollout_percentage',100) }}" min="1" max="100" required><span class="input-group__suffix">%</span></div>
                    <small>Increase toward 100% after monitoring.</small>
                </label>
                <label class="field">Version
                    <input name="version" value="{{ old('version') }}" placeholder="1.2.0" pattern="[0-9]+\.[0-9]+\.[0-9]+.*" required>
                </label>
                <label class="field">Android build number
                    <input type="number" name="build_number" value="{{ old('build_number') }}" min="1" placeholder="3">
                    <small>Required for Android; use a higher number every release.</small>
                </label>
                <label class="field">Minimum supported version
                    <input name="minimum_supported_version" value="{{ old('minimum_supported_version') }}" placeholder="1.1.0">
                    <small>Versions below this value will be forced to update.</small>
                </label>
                <label class="field field--full">Package file
                    <input type="file" name="package" accept=".apk,.zip" required>
                    <small>Staff/Customer Android uses .apk; Staff Windows uses the complete release .zip. PHP limits: upload {{ $uploadMax }}, request {{ $postMax }}.</small>
                </label>
                <label class="field field--full">Release notes
                    <textarea name="release_notes" rows="5" placeholder="What changed in this version?">{{ old('release_notes') }}</textarea>
                </label>
                <label class="field"><span><input type="checkbox" name="mandatory" value="1" @checked(old('mandatory'))> Mandatory update</span><small>Users cannot dismiss this release.</small></label>
                <label class="field"><span><input type="checkbox" name="publish" value="1" @checked(old('publish',true))> Publish immediately</span><small>Replaces the currently published package for this target.</small></label>
            </div>
            <div class="form-actions"><button class="button" type="submit"><x-icon name="update" :size="17" /> Upload package</button></div>
        </form>
    </section>

    <section class="card">
        <div class="card__header"><div><h2>Published channels</h2><p>One active release per target in Stable, Pilot, and Beta</p></div></div>
        <div class="card__body stack">
            @foreach(['stable'=>'Stable','pilot'=>'Pilot','beta'=>'Beta'] as $channel=>$channelLabel)
                <span class="eyebrow">{{ $channelLabel }}</span>
                @foreach($targetLabels as $target => $label)
                @php($release=$latest->get($channel)?->get($target))
                <article class="game-row">
                    <span class="game-row__icon"><x-icon name="{{ str_contains($target,'windows') ? 'counter' : 'device' }}" :size="23" /></span>
                    <span class="game-row__copy"><strong>{{ $label }}</strong>@if($release)<small>Version {{ $release->version }}{{ $release->build_number ? ' · build '.$release->build_number : '' }}</small><span>{{ number_format($release->file_size/1048576,2) }} MB · {{ str($release->sha256)->limit(18) }}</span>@else<small>No package published</small><span>Devices will report that no update is available.</span>@endif</span>
                    <x-status :value="$release ? 'published' : 'disabled'" :label="$release ? 'Published' : 'Empty'" />
                </article>
                @endforeach
            @endforeach
        </div>
    </section>
</div>

<section class="card" style="margin-top:18px">
    <div class="card__header"><div><h2>Release history</h2><p>Uploaded packages and publishing state</p></div></div>
    <div class="table-wrap"><table class="data-table data-table--responsive">
        <thead><tr><th>Application</th><th>Channel</th><th>Version</th><th>Package</th><th>Policy</th><th>Checksum</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>@forelse($releases as $release)
            <tr>
                <td><span class="table-primary"><strong>{{ ucfirst($release->app) }} {{ ucfirst($release->platform) }}</strong><small>Uploaded by {{ $release->uploader?->name ?: 'System' }} · {{ $release->created_at->diffForHumans() }}</small></span></td>
                <td data-label="Channel"><strong>{{ ucfirst($release->channel ?: 'stable') }}</strong><br><small>{{ $release->rollout_percentage ?: 100 }}% rollout</small></td>
                <td data-label="Version"><strong>{{ $release->version }}</strong><br><small>{{ $release->build_number ? 'Build '.$release->build_number : 'Desktop package' }}</small></td>
                <td data-label="Package"><span class="table-primary"><strong>{{ $release->original_filename }}</strong><small>{{ number_format($release->file_size/1048576,2) }} MB</small></span></td>
                <td data-label="Policy">{{ $release->mandatory ? 'Mandatory' : 'Optional' }}@if($release->minimum_supported_version)<br><small>Minimum {{ $release->minimum_supported_version }}</small>@endif</td>
                <td data-label="Checksum"><code title="{{ $release->sha256 }}">{{ str($release->sha256)->limit(20) }}</code><br><small>{{ $release->verified_at ? 'Package verified' : 'Legacy / unverified' }}</small></td>
                <td data-label="Status"><x-status :value="$release->is_published ? 'published' : 'disabled'" :label="$release->is_published ? 'Published' : 'Draft'" /></td>
                <td data-label="Action">@if($release->is_published)<form method="post" action="{{ route('admin.app-updates.unpublish',$release) }}">@csrf<button class="button button--secondary button--small" data-confirm="Unpublish version {{ $release->version }}? Devices will stop receiving this package." data-confirm-tone="danger">Unpublish</button></form>@else<form method="post" action="{{ route('admin.app-updates.publish',$release) }}">@csrf<button class="button button--small" data-confirm="Publish version {{ $release->version }} for {{ $release->app }} {{ $release->platform }}?">Publish</button></form>@endif</td>
            </tr>
        @empty<tr><td colspan="8"><x-empty-state icon="update" title="No application packages uploaded" message="Upload the first signed release package above." /></td></tr>@endforelse</tbody>
    </table></div>
</section>

<section class="card" style="margin-top:18px">
    <div class="card__header"><div><h2>Connected-device versions</h2><p>Updated whenever an application checks the local release server</p></div></div>
    <div class="table-wrap"><table class="data-table data-table--responsive">
        <thead><tr><th>Installation</th><th>Application</th><th>Channel</th><th>Version</th><th>Account / device</th><th>IP address</th><th>Last check</th><th>Status</th></tr></thead>
        <tbody>@forelse($installations as $installation)
            <tr>
                <td><span class="table-primary"><strong>{{ $installation->device_name }}</strong><small>{{ str($installation->installation_uuid)->limit(20) }}</small></span></td>
                <td data-label="Application">{{ ucfirst($installation->app) }} {{ ucfirst($installation->platform) }}</td>
                <td data-label="Channel">{{ ucfirst($installation->channel ?: 'stable') }}</td>
                <td data-label="Version">{{ $installation->current_version }}{{ $installation->build_number ? ' · '.$installation->build_number : '' }}</td>
                <td data-label="Identity">{{ $installation->user?->name ?: $installation->device?->device_name ?: 'Unpaired / signed out' }}</td>
                <td data-label="IP">{{ $installation->ip_address ?: '—' }}</td>
                <td data-label="Last check">{{ $installation->last_checked_at->diffForHumans() }}</td>
                <td data-label="Status"><x-status :value="$installation->update_available ? 'pending' : 'healthy'" :label="$installation->update_available ? 'Update ready' : 'Current'" /></td>
            </tr>
        @empty<tr><td colspan="8"><x-empty-state icon="device" title="No version reports yet" message="Reports appear after the upgraded apps perform their first update check." /></td></tr>@endforelse</tbody>
    </table></div>
</section>
@endsection
