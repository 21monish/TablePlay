@extends('layouts.app')
@section('title', 'Overview')
@section('eyebrow', 'Administration')
@section('page-title', 'Restaurant overview')

@section('content')
<div class="page-heading">
    <div><h2>Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ auth()->user()->name }}</h2><p>A live view of today’s restaurant activity across tables, orders, devices, and guest requests.</p></div>
    <div class="page-heading__actions"><a class="button button--secondary" href="{{ route('admin.system') }}"><x-icon name="health" :size="17" /> System health</a><a class="button" href="{{ route('counter.index') }}"><x-icon name="counter" :size="17" /> Open counter</a></div>
</div>

<section class="stats-grid">
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="report" /></span><span class="stat-card__meta">Today</span></div><div class="stat-card__value">{{ $appSettings?->currency ?: 'INR' }} {{ number_format($stats['sales'], 0) }}</div><div class="stat-card__label">Collected revenue</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="order" /></span><span class="stat-card__meta">Today</span></div><div class="stat-card__value">{{ $stats['orders'] }}</div><div class="stat-card__label">Orders placed</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="tables" /></span><span class="stat-card__meta">Live</span></div><div class="stat-card__value">{{ $stats['open_tables'] }}</div><div class="stat-card__label">Open table sessions</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="clock" /></span><span class="stat-card__meta">Needs action</span></div><div class="stat-card__value">{{ $stats['pending'] }}</div><div class="stat-card__label">Orders awaiting confirmation</div></article>
</section>

<div class="content-grid content-grid--2">
    <section class="card">
        <div class="card__header"><div><h2>Dining floor</h2><p>Live occupancy and table session state</p></div><a class="button button--secondary button--small" href="{{ route('admin.tables') }}">Manage tables <x-icon name="arrow" :size="14" /></a></div>
        <div class="card__body">
            <div class="table-map">
                @foreach($tables as $table)
                    @php($session=$table->sessions->first())
                    <article class="table-tile table-tile--{{ $table->status }}"><div class="table-tile__top"><span class="table-tile__code">{{ $table->table_code }}</span><x-status :value="$table->status" /></div><div class="table-tile__meta">{{ $table->table_name }} &middot; {{ $table->capacity }} seats</div><div class="table-tile__meta">{{ $session ? $session->orders->count().' order(s)' : 'No active session' }}</div></article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__header"><div><h2>Guest requests</h2><p>Pending waiter, water, and billing calls</p></div><span class="status-badge status-badge--{{ $requests->count() ? 'warning' : 'healthy' }}"><i></i>{{ $requests->count() }} active</span></div>
        <div class="card__body">
            @forelse($requests as $request)
                <div class="quick-list__item"><div class="quick-list__main"><span class="quick-list__icon"><x-icon name="bell" :size="18" /></span><span class="quick-list__copy"><strong>{{ $request->tableSession->diningTable->table_code }} &middot; {{ str($request->request_type)->replace('_',' ')->title() }}</strong><small>{{ $request->message ?: 'No additional message' }} &middot; {{ $request->created_at->diffForHumans() }}</small></span></div><x-status :value="$request->status" /></div>
            @empty
                <x-empty-state icon="check" title="All requests handled" message="New guest requests will appear here immediately." />
            @endforelse
        </div>
    </section>
</div>

<div class="content-grid content-grid--2" style="margin-top:18px">
    <section class="card">
        <div class="card__header"><div><h2>Recent orders</h2><p>Latest activity across all tables</p></div><a class="button button--secondary button--small" href="{{ route('counter.index') }}">View queue</a></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Order</th><th>Table</th><th>Items</th><th>Total</th><th>Status</th></tr></thead><tbody>
        @forelse($recentOrders as $order)<tr><td><span class="table-primary"><strong>{{ $order->order_number }}</strong><small>{{ $order->created_at->diffForHumans() }}</small></span></td><td>{{ $order->tableSession->diningTable->table_code }}</td><td>{{ $order->items->sum('quantity') }}</td><td>{{ $appSettings?->currency ?: 'INR' }} {{ number_format($order->total_amount,2) }}</td><td><x-status :value="$order->status" /></td></tr>@empty<tr><td colspan="5"><x-empty-state icon="order" title="No orders yet" /></td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="card">
        <div class="card__header"><div><h2>Device pulse</h2><p>Most recently seen customer tablets</p></div><a class="button button--secondary button--small" href="{{ route('admin.tables') }}">All devices</a></div>
        <div class="card__body quick-list">
            @forelse($devices as $device)@php($online=$device->last_seen_at?->gte(now()->subMinutes($appSettings?->device_offline_minutes ?: 5)))<div class="quick-list__item"><div class="quick-list__main"><span class="quick-list__icon"><x-icon name="device" :size="18" /></span><span class="quick-list__copy"><strong>{{ $device->device_name }}</strong><small>{{ $device->ip_address ?: 'No IP' }} &middot; {{ $device->last_seen_at?->diffForHumans() ?: 'Never connected' }}</small></span></div><x-status :value="$online ? 'online' : 'offline'" /></div>@empty<x-empty-state icon="device" title="No tablets registered" message="Open the TablePlay tablet app to register the first device." />@endforelse
        </div>
    </section>
</div>
@endsection
