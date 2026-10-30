@extends('layouts.app')
@section('title', 'Restaurants and profits')
@section('eyebrow', 'TablePlay Super Admin')
@section('page-title', 'Restaurants & profits')
@section('content')
<div class="page-heading"><div><h2>Restaurant performance directory</h2><p>Owner details, current plan and monthly financial summaries. Subscription receipts belong to TablePlay; food sales and reported profit belong to the restaurant.</p></div><a class="button button--secondary" href="{{ route('superadmin.overview', ['month' => $month->format('Y-m')]) }}">Platform overview</a></div>
<section class="card"><form class="card__body form-grid" method="get" action="{{ route('superadmin.restaurants') }}">
<label class="field">Search<input name="q" type="search" value="{{ request('q') }}" maxlength="100" placeholder="Restaurant, owner or email"></label><label class="field">Account status<select name="status"><option value="">All accounts</option>@foreach(['active', 'suspended', 'closed'] as $status)<option @selected(request('status') === $status) value="{{ $status }}">{{ ucfirst($status) }}</option>@endforeach</select></label><label class="field">Month<input type="month" name="month" min="2000-01" max="{{ now()->format('Y-m') }}" value="{{ $month->format('Y-m') }}" required></label><div class="form-actions"><button class="button" type="submit">Filter restaurants</button><a class="button button--secondary" href="{{ route('superadmin.restaurants') }}">Reset</a></div>
</form></section>
<section class="card" style="margin-top:20px"><div class="card__header"><div><h2>{{ $restaurants->total() }} matching restaurants</h2><p>Monthly figures: {{ $month->format('F Y') }}. All profit figures below are owner-reported, not audited.</p></div></div><div class="table-wrap"><table class="data-table data-table--responsive"><thead><tr><th>Restaurant / owner</th><th>Account / plan</th><th>TablePlay net cash</th><th>Restaurant financials</th><th>Action</th></tr></thead><tbody>
@forelse($restaurants as $restaurant)
@php($subscription = $restaurant->currentSubscription)
@php($report = $restaurant->financialReports->first())
<tr><td><span class="table-primary"><strong>{{ $restaurant->name }}</strong><small>{{ $restaurant->owner_name ?: $restaurant->ownerUser?->name ?: 'No owner name' }}</small><small>{{ $restaurant->owner_email ?: $restaurant->ownerUser?->email ?: 'No email' }}</small><small>{{ $restaurant->owner_phone ?: 'No phone' }}</small></span></td>
<td data-label="Account / plan"><span class="table-primary"><x-status :value="$restaurant->status" /><strong>{{ $subscription?->plan?->name ?? 'No subscription' }}</strong><small>{{ $subscription ? ucfirst($subscription->status) : 'Not subscribed' }}@if($subscription?->expires_at) &middot; expires {{ $subscription->expires_at->format('d M Y') }}@endif</small></span></td>
<td data-label="TablePlay net cash">@forelse($cashByRestaurant->get($restaurant->id, collect()) as $row)<div>{{ $row->currency === 'UNASSIGNED' ? 'Unassigned' : $row->currency }} {{ number_format($row->net_cents / 100, 2) }}</div>@empty<span class="subtle">No recorded cash movement</span>@endforelse</td>
<td data-label="Restaurant financials">@if($report)<span class="table-primary"><strong>{{ $report->currency }} {{ number_format($report->profit_cents / 100, 2) }} profit / loss</strong><small>Net sales {{ number_format((float) $report->net_sales, 2) }} &middot; expenses {{ number_format((float) $report->expenses, 2) }}</small><small>Owner-reported &middot; {{ $report->updated_at->format('d M Y H:i') }}</small></span>@if($report->notes)<details><summary>Owner notes</summary><p>{{ $report->notes }}</p></details>@endif @else<span class="subtle">Not reported for this month</span>@endif</td>
<td data-label="Action"><a class="button button--secondary button--small" href="{{ route('superadmin.cloud.index', ['q' => $restaurant->name]) }}#customers">Open customer billing</a></td></tr>
@empty<tr><td colspan="5">No restaurants match these filters.</td></tr>@endforelse
</tbody></table></div><div class="card__body">@include('superadmin._pagination', ['records' => $restaurants, 'label' => 'Restaurants'])</div></section>
@endsection
