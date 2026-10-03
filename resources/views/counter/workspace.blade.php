@extends('layouts.app')
@section('title', 'Counter')
@section('eyebrow', 'Live operations')
@section('page-title', 'Counter command center')

@section('content')
<div class="page-heading"><div><h2>Service at a glance</h2><p>Confirm incoming orders, resolve guest requests, manage game access, and close bills.</p></div><div class="page-heading__actions"><button class="button button--secondary" type="button" onclick="window.location.reload()"><x-icon name="refresh" :size="17" /> Refresh</button>@if(auth()->user()->hasRole('admin'))<a class="button" href="{{ route('kitchen.index') }}"><x-icon name="kitchen" :size="17" /> Kitchen board</a>@endif</div></div>
<section class="stats-grid">
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="bell" /></span><span class="stat-card__meta">Needs action</span></div><div class="stat-card__value">{{ $orders->where('status','pending')->count() }}</div><div class="stat-card__label">Incoming orders</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="tables" /></span><span class="stat-card__meta">Live</span></div><div class="stat-card__value">{{ $sessions->count() }}</div><div class="stat-card__label">Open tables</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="game" /></span><span class="stat-card__meta">Unlocked</span></div><div class="stat-card__value">{{ $games->count() }}</div><div class="stat-card__label">Active game sessions</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="bell" /></span><span class="stat-card__meta">Guest care</span></div><div class="stat-card__value">{{ $requests->count() }}</div><div class="stat-card__label">Service requests</div></article>
</section>

<div class="content-grid content-grid--2">
    <section><div class="section-heading"><div><h2>Order queue</h2><p>Oldest requests appear first</p></div><span class="status-badge status-badge--{{ $orders->where('status','pending')->count() ? 'warning' : 'healthy' }}"><i></i>{{ $orders->count() }} active</span></div><div class="content-grid content-grid--equal">
        @forelse($orders as $order)
            <article class="card order-card"><div class="order-card__head"><div class="order-card__identity"><span class="table-code">{{ $order->tableSession->diningTable->table_code }}</span><span class="order-card__number"><strong>{{ $order->order_number }}</strong><small>Placed {{ $order->created_at->diffForHumans() }}</small></span></div><x-status :value="$order->status" /></div><div class="order-card__items">@foreach($order->items as $item)<div class="order-line"><span class="order-line__qty">{{ $item->quantity }}×</span><span class="order-line__name">{{ $item->item_name_snapshot }}</span><span>{{ $appSettings?->currency ?: 'INR' }} {{ number_format($item->line_total,0) }}</span>@if($item->special_instruction)<span class="order-line__note">{{ $item->special_instruction }}</span>@endif</div>@endforeach</div>@if($order->customer_note)<div class="order-card__note"><strong>Guest note:</strong> {{ $order->customer_note }}</div>@endif
                @if($order->status==='pending')<div class="order-card__actions"><form method="post" action="{{ route('counter.orders.confirm',$order) }}">@csrf<button class="button button--accent" type="submit"><x-icon name="check" :size="16" /> Confirm & unlock games</button></form><form class="inline-form" method="post" action="{{ route('counter.orders.reject',$order) }}">@csrf<label class="field"><span class="sr-only">Rejection reason</span><input name="reason" placeholder="Reason required" required></label><button class="button button--danger" type="submit">Reject</button></form></div>@else<div class="card__footer"><span class="muted">In progress with the kitchen team</span></div>@endif
            </article>
        @empty<section class="card" style="grid-column:1/-1"><x-empty-state icon="check" title="Order queue is clear" message="New tablet orders will appear here for confirmation." /></section>@endforelse
    </div></section>
    <aside class="stack">
        <section class="card"><div class="card__header"><div><h2>Guest requests</h2><p>Waiter, water, and bill calls</p></div></div><div class="card__body quick-list">@forelse($requests as $request)<div class="quick-list__item" style="align-items:flex-start"><div class="quick-list__main"><span class="quick-list__icon"><x-icon name="bell" :size="18" /></span><span class="quick-list__copy"><strong>{{ $request->tableSession->diningTable->table_code }} &middot; {{ str($request->request_type)->replace('_',' ')->title() }}</strong><small>{{ $request->message ?: 'No message' }} &middot; {{ $request->created_at->diffForHumans() }}</small><form class="inline-form" method="post" action="{{ route('counter.requests.update',$request) }}" style="margin-top:8px">@csrf<select name="status"><option value="acknowledged">Acknowledge</option><option value="completed">Complete</option><option value="cancelled">Cancel</option></select><button class="button button--small" type="submit">Update</button></form></span></div></div>@empty<x-empty-state icon="check" title="All guests attended" message="No open service requests." />@endforelse</div></section>
        <section class="card game-timer-panel">
            <div class="card__header"><div><h2>Game timers</h2><p>Server-controlled access by table</p></div><span class="timer-live"><i></i> Live</span></div>
            <div class="card__body game-timer-list">
                @forelse($games as $game)
                    <article class="game-timer" data-game-timer data-expires="{{ $game->expires_at->timestamp }}">
                        <div class="game-timer__top">
                            <div class="game-timer__identity"><span class="quick-list__icon"><x-icon name="game" :size="18" /></span><span><strong>{{ $game->tableSession->diningTable->table_code }}</strong><small>Game access unlocked</small></span></div>
                            <span class="game-countdown">Calculating&hellip;</span>
                        </div>
                        <div class="game-timer__track"><span></span></div>
                        <div class="game-timer__actions">
                            @foreach([15, 30] as $minutes)
                                <form method="post" action="{{ route('counter.games.extend',$game) }}">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}"><button class="button button--secondary button--small" type="submit">+{{ $minutes }} min</button></form>
                            @endforeach
                            <form class="inline-form game-timer__custom" method="post" action="{{ route('counter.games.extend',$game) }}">@csrf<input aria-label="Custom extension minutes" type="number" name="minutes" value="15" min="1" max="240"><button class="button button--small" type="submit">Extend</button></form>
                            <form method="post" action="{{ route('counter.games.stop',$game) }}">@csrf<button class="button button--danger button--small" data-confirm="Lock games for {{ $game->tableSession->diningTable->table_code }}?">Stop</button></form>
                        </div>
                    </article>
                @empty
                    <x-empty-state icon="game" title="No games unlocked" message="Confirm a table order to start its game timer." />
                @endforelse
            </div>
        </section>
    </aside>
</div>

<section class="card" style="margin-top:20px"><div class="card__header"><div><h2>Billing & table closure</h2><p>Generate bills, record cash, and print receipts</p></div></div><div class="table-wrap"><table class="data-table data-table--responsive"><thead><tr><th>Table / session</th><th>Orders</th><th>Order value</th><th>Status</th><th>Billing action</th></tr></thead><tbody>@forelse($sessions as $session)@php($subtotal=$session->orders->whereNotIn('status',['cancelled','rejected'])->sum('total_amount'))<tr><td><span class="table-primary"><strong>{{ $session->diningTable->table_code }} &middot; {{ $session->diningTable->table_name }}</strong><small>{{ $session->session_code }} &middot; Open {{ $session->opened_at->diffForHumans() }}</small></span></td><td data-label="Orders">{{ $session->orders->count() }}</td><td data-label="Order value">{{ $appSettings?->currency ?: 'INR' }} {{ number_format($subtotal,2) }}</td><td data-label="Status"><x-status :value="$session->status" /></td><td data-label="Action">@if(!$session->bill)<form class="inline-form" method="post" action="{{ route('counter.sessions.bill',$session) }}">@csrf<label class="field">Discount<input type="number" step="0.01" name="discount_amount" value="0" min="0" style="width:100px"></label><button class="button button--small" type="submit">Generate bill</button></form>@elseif($session->bill->payment_status==='unpaid')<div class="inline-form"><span class="strong">{{ $session->bill->bill_number }} &middot; {{ $appSettings?->currency ?: 'INR' }} {{ number_format($session->bill->grand_total,2) }}</span><form class="inline-form" method="post" action="{{ route('counter.bills.pay',$session->bill) }}">@csrf<input type="number" step="0.01" name="received_amount" min="{{ $session->bill->grand_total }}" placeholder="Cash received" required><button class="button button--accent button--small" type="submit">Record cash</button></form><a class="button button--secondary button--small" href="{{ route('counter.bills.receipt',$session->bill) }}" target="_blank">Receipt</a></div>@else<div class="table-actions"><x-status value="paid" /><a class="button button--secondary button--small" href="{{ route('counter.bills.receipt',$session->bill) }}" target="_blank"><x-icon name="receipt" :size="15" /> Receipt</a></div>@endif</td></tr>@empty<tr><td colspan="5"><x-empty-state icon="tables" title="No open table sessions" /></td></tr>@endforelse</tbody></table></div></section>
@endsection

@push('scripts')
<script>
function gameTimers() {
    document.querySelectorAll('[data-game-timer]').forEach(card => {
        const left = Math.max(0, Number(card.dataset.expires) - Math.floor(Date.now() / 1000));
        const hours = Math.floor(left / 3600);
        const minutes = Math.floor((left % 3600) / 60);
        const seconds = left % 60;
        const value = hours > 0
            ? `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
            : `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        card.querySelector('.game-countdown').textContent = `${value} left`;
        card.classList.toggle('game-timer--warning', left <= 300);
        card.classList.toggle('game-timer--ended', left === 0);
    });
}
gameTimers();
setInterval(gameTimers, 1000);
</script>
@endpush
