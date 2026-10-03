@extends('layouts.app')
@section('title', 'Reports')
@section('eyebrow', 'Administration')
@section('page-title', 'Reports & audit')

@section('content')
<div class="page-heading"><div><h2>Business performance</h2><p>Review order value, recorded payments, daily sales, and administrator activity.</p></div></div>
<section class="stats-grid">
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="report" /></span></div><div class="stat-card__value">{{ $appSettings?->currency ?: 'INR' }} {{ number_format($summary['revenue'],0) }}</div><div class="stat-card__label">Recorded revenue</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="receipt" /></span></div><div class="stat-card__value">{{ number_format($summary['payments']) }}</div><div class="stat-card__label">Completed payments</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="order" /></span></div><div class="stat-card__value">{{ number_format($summary['orders']) }}</div><div class="stat-card__label">Accepted orders</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="tables" /></span></div><div class="stat-card__value">{{ $appSettings?->currency ?: 'INR' }} {{ number_format($summary['average'],0) }}</div><div class="stat-card__label">Average order value</div></article>
</section>
<div class="content-grid content-grid--equal">
    <section class="card"><div class="card__header"><div><h2>Daily order sales</h2><p>Last 30 days of non-cancelled order value</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Date</th><th>Orders</th><th class="text-right">Order value</th></tr></thead><tbody>@forelse($sales as $day)<tr><td>{{ \Illuminate\Support\Carbon::parse($day->sale_date)->format('d M Y') }}</td><td>{{ $day->orders_count }}</td><td class="text-right strong">{{ $appSettings?->currency ?: 'INR' }} {{ number_format($day->total,2) }}</td></tr>@empty<tr><td colspan="3"><x-empty-state icon="report" title="No sales data yet" /></td></tr>@endforelse</tbody></table></div></section>
    <section class="card"><div class="card__header"><div><h2>Audit trail</h2><p>Most recent 100 management actions</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Record</th></tr></thead><tbody>@forelse($audits as $audit)<tr><td class="nowrap"><span class="table-primary"><strong>{{ $audit->created_at->format('d M, H:i') }}</strong><small>{{ $audit->created_at->diffForHumans() }}</small></span></td><td>{{ $audit->user?->name ?: 'System' }}</td><td>{{ str($audit->action)->replace(['.','_'],' ')->title() }}</td><td>{{ class_basename($audit->entity_type ?: '—') }}{{ $audit->entity_id ? ' #'.$audit->entity_id : '' }}</td></tr>@empty<tr><td colspan="4"><x-empty-state icon="report" title="No audit events" /></td></tr>@endforelse</tbody></table></div></section>
</div>
@endsection
