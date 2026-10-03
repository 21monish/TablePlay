@extends('layouts.app')
@section('title', 'Tables & devices')
@section('eyebrow', 'Administration')
@section('page-title', 'Tables & devices')

@section('content')
@php
    $activePairings = $tables->sum(fn ($table) => $table->pairings->count());
    $customerAppEnabled = (bool) data_get($licenseState, 'licensed', false) && (bool) data_get($licenseState, 'features.customer_app', false);
    $tabletLimit = data_get($licenseState, 'limits.paired_tables');
    $canAddPairing = $customerAppEnabled && ($tabletLimit === null || $activePairings < (int) $tabletLimit);
@endphp
<div class="page-heading"><div><h2>Dining floor setup</h2><p>Create tables, manage their operational state, and control tablet pairing from one place.</p></div></div>
<section class="stats-grid">
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="tables" /></span></div><div class="stat-card__value">{{ $tables->count() }}</div><div class="stat-card__label">Total tables</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="device" /></span></div><div class="stat-card__value">{{ $devices->count() }}</div><div class="stat-card__label">Registered devices</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="check" /></span></div><div class="stat-card__value">{{ $activePairings }}</div><div class="stat-card__label">Active pairings</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="health" /></span></div><div class="stat-card__value">{{ $devices->filter(fn($d)=>$d->last_seen_at?->gte(now()->subMinutes($appSettings?->device_offline_minutes ?: 5)))->count() }}</div><div class="stat-card__label">Devices online now</div></article>
</section>

<div class="content-grid content-grid--equal">
    <section class="card"><div class="card__header"><div><h2>Add a table</h2><p>Use short codes that staff can identify quickly</p></div><span class="stat-card__icon"><x-icon name="plus" /></span></div><form class="card__body" method="post" action="{{ route('admin.tables.store') }}">@csrf<div class="form-grid form-grid--3"><label class="field">Table code<input name="table_code" placeholder="T11" value="{{ old('table_code') }}" required></label><label class="field">Display name<input name="table_name" placeholder="Garden Table" value="{{ old('table_name') }}" required></label><label class="field">Seats<input type="number" name="capacity" min="1" max="50" value="{{ old('capacity',4) }}" required></label></div><div class="form-actions"><button class="button" type="submit"><x-icon name="plus" :size="16" /> Add table</button></div></form></section>
    <section class="card"><div class="card__header"><div><h2>Pair a tablet</h2><p>{{ $customerAppEnabled ? ($tabletLimit === null ? 'Unlimited customer-table pairings' : $activePairings.' of '.$tabletLimit.' plan slots in use') : 'Customer Table app is not included in this plan' }}</p></div><span class="stat-card__icon"><x-icon name="device" /></span></div>@if($canAddPairing)<form class="card__body" method="post" action="{{ route('admin.pairings.store') }}">@csrf<div class="form-grid"><label class="field">Unpaired tablet<select name="device_id" required><option value="">Select tablet</option>@foreach($devices->filter(fn($d)=>$d->device_type==='tablet'&&!$d->pairings->count()) as $device)<option value="{{ $device->id }}">{{ $device->device_name }}</option>@endforeach</select></label><label class="field">Available table<select name="dining_table_id" required><option value="">Select table</option>@foreach($tables->filter(fn($t)=>$t->is_active&&$t->status!=='disabled'&&!$t->pairings->count()) as $table)<option value="{{ $table->id }}">{{ $table->table_code }} &middot; {{ $table->table_name }}</option>@endforeach</select></label></div><div class="form-actions"><button class="button" type="submit">Pair selected device</button></div></form>@else<div class="card__body"><div class="modal-note"><x-icon name="license" :size="16" /><span>{{ $customerAppEnabled ? 'Your current tablet limit is full. Unpair an unused tablet or upgrade the plan.' : 'Upgrade to a plan with the Customer Table app before pairing tablets.' }}</span></div><div class="form-actions"><a class="button" href="{{ route('admin.license.index') }}">View licence and plan</a></div></div>@endif</section>
</div>

<section class="card" style="margin-top:18px"><div class="card__header"><div><h2>Table directory</h2><p>Current availability and paired customer device</p></div></div><div class="table-wrap"><table class="data-table data-table--responsive"><thead><tr><th>Table</th><th>Capacity</th><th>Status</th><th>Tablet</th><th>Actions</th></tr></thead><tbody>
@forelse($tables as $table)
    @php($pairing=$table->pairings->first())
    <tr>
        <td><span class="table-primary"><strong>{{ $table->table_code }} &middot; {{ $table->table_name }}</strong><small>{{ $table->is_active ? 'Active on floor' : 'Hidden from service' }} &middot; {{ $table->sessions_count }} recorded visit(s)</small></span></td>
        <td data-label="Capacity">{{ $table->capacity }} seats</td>
        <td data-label="Status"><x-status :value="$table->status" /></td>
        <td data-label="Tablet">@if($pairing)<span class="table-primary"><strong>{{ $pairing->device->device_name }}</strong><small>{{ $pairing->device->ip_address ?: 'No IP address' }}</small></span>@else<span class="subtle">Not paired</span>@endif</td>
        <td data-label="Actions">
            <div class="table-actions">
                @if($pairing)<form method="post" action="{{ route('admin.pairings.unpair',$pairing) }}">@csrf<button class="button button--secondary button--small" data-confirm="Unpair this tablet from {{ $table->table_code }}?" data-confirm-tone="danger">Unpair</button></form>@elseif($canAddPairing)<form method="post" action="{{ route('admin.setup.pairing',$table) }}">@csrf<button class="button button--secondary button--small" type="submit">Pair QR</button></form>@else<a class="button button--secondary button--small" href="{{ route('admin.license.index') }}">Pairing locked</a>@endif
                <button class="button button--small" type="button" data-dialog-open="table-editor-{{ $table->id }}"><x-icon name="settings" :size="15" /> Manage</button>
                <dialog class="modal-dialog" id="table-editor-{{ $table->id }}" aria-labelledby="table-editor-title-{{ $table->id }}">
                    <div class="modal-dialog__surface">
                        <header class="modal-dialog__header"><div><span class="eyebrow">Table management</span><h2 id="table-editor-title-{{ $table->id }}">{{ $table->table_code }} &middot; {{ $table->table_name }}</h2></div><button class="icon-button" type="button" data-dialog-close aria-label="Close table editor"><x-icon name="close" /></button></header>
                        <form class="modal-dialog__body" method="post" action="{{ route('admin.tables.update',$table) }}">@csrf @method('PUT')
                            <div class="form-grid"><label class="field field--full">Display name<input name="table_name" value="{{ $table->table_name }}" required></label><label class="field">Seats<input type="number" name="capacity" min="1" max="50" value="{{ $table->capacity }}" required></label><label class="field">Status<select name="status" required>@foreach(['available'=>'Available','occupied'=>'Occupied','reserved'=>'Reserved','cleaning'=>'Cleaning','disabled'=>'Disabled'] as $value=>$label)<option value="{{ $value }}" @selected($table->status===$value)>{{ $label }}</option>@endforeach</select></label><label class="check-row field--full"><input type="checkbox" name="is_active" value="1" @checked($table->is_active)> Active and available to restaurant workflows</label></div>
                            <div class="modal-note"><x-icon name="help" :size="16" /><span>Disabling a table also unpairs its tablet. A table with an open visit cannot be disabled.</span></div>
                            <div class="form-actions"><button class="button button--secondary" type="button" data-dialog-close>Cancel</button><button class="button" type="submit"><x-icon name="check" :size="16" /> Save table</button></div>
                        </form>
                        <section class="table-removal">
                            <div><strong>{{ $table->sessions_count ? 'Archive table' : 'Delete unused table' }}</strong><p>@if($table->active_sessions_count)This table is currently serving guests and cannot be removed.@elseif($table->sessions_count)The table has service history. Removing it will safely disable and archive it while preserving reports and bills.@elseThis table has no service history and can be permanently deleted. Any tablet pairing will be removed.@endif</p></div>
                            @if($table->active_sessions_count)
                                <button class="button button--danger button--small" type="button" disabled>Active visit in progress</button>
                            @else
                                <form method="post" action="{{ route('admin.tables.destroy',$table) }}">@csrf @method('DELETE')<button class="button button--danger button--small" type="submit" data-confirm="@if($table->sessions_count)Archive {{ $table->table_code }} and unpair its tablet? Historical orders and bills will be preserved.@else Permanently delete unused {{ $table->table_code }}? This cannot be undone.@endif">{{ $table->sessions_count ? 'Archive table' : 'Delete permanently' }}</button></form>
                            @endif
                        </section>
                    </div>
                </dialog>
            </div>
        </td>
    </tr>
@empty
    <tr><td colspan="5"><x-empty-state icon="tables" title="No tables configured" message="Add the first dining table above." /></td></tr>
@endforelse
</tbody></table></div></section>

<section class="card" style="margin-top:18px"><div class="card__header"><div><h2>Registered devices</h2><p>Connection history and application versions</p></div></div><div class="table-wrap"><table class="data-table data-table--responsive"><thead><tr><th>Device</th><th>Type</th><th>Table</th><th>IP address</th><th>Last seen</th><th>Status</th></tr></thead><tbody>@forelse($devices as $device)@php($online=$device->last_seen_at?->gte(now()->subMinutes($appSettings?->device_offline_minutes ?: 5)))<tr><td><span class="table-primary"><strong>{{ $device->device_name }}</strong><small>{{ str($device->device_uuid)->limit(18) }}</small></span></td><td data-label="Type">{{ str($device->device_type)->title() }}</td><td data-label="Table">{{ $device->pairings->first()?->diningTable?->table_code ?: 'Unpaired' }}</td><td data-label="IP">{{ $device->ip_address ?: '—' }}</td><td data-label="Last seen">{{ $device->last_seen_at?->diffForHumans() ?: 'Never' }}</td><td data-label="Status"><x-status :value="$online ? 'online' : 'offline'" /></td></tr>@empty<tr><td colspan="6"><x-empty-state icon="device" title="No devices registered" /></td></tr>@endforelse</tbody></table></div></section>
@endsection
