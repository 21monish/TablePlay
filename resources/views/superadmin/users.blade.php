@extends('layouts.app')
@section('title', 'Platform users')
@section('eyebrow', 'TablePlay Super Admin')
@section('page-title', 'Users')
@section('content')
<div class="page-heading"><div><h2>Cloud user directory</h2><p>Search registered accounts, roles, verification and access status. Restaurant-local staff and customer tablets are not automatically uploaded.</p></div></div>
<section class="card"><form class="card__body form-grid" method="get" action="{{ route('superadmin.users') }}">
    <label class="field">Search<input type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Name, username or email"></label>
    <label class="field">Role<select name="role"><option value="">All roles</option>@foreach($roles as $role)<option value="{{ $role->name }}" @selected(request('role') === $role->name)>{{ $role->display_name }}</option>@endforeach</select></label>
    <label class="field">Account status<select name="status"><option value="">All, including deleted</option>@foreach(['active' => 'Active', 'disabled' => 'Disabled', 'deleted' => 'Deleted'] as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
    <label class="field">Email verification<select name="verified"><option value="">All</option><option value="yes" @selected(request('verified') === 'yes')>Verified</option><option value="no" @selected(request('verified') === 'no')>Not verified</option></select></label>
    <div class="form-actions field--full"><button class="button" type="submit">Search users</button><a class="button button--secondary" href="{{ route('superadmin.users') }}">Reset</a></div>
</form></section>
<section class="card" style="margin-top:20px"><div class="card__header"><h2>{{ $users->total() }} matching accounts</h2><span class="status-badge">Read-only directory</span></div><div class="table-wrap"><table class="data-table data-table--responsive"><thead><tr><th>User</th><th>Role</th><th>Restaurant</th><th>Status</th><th>Email</th><th>Last login</th></tr></thead><tbody>
@forelse($users as $user)<tr><td><span class="table-primary"><strong>{{ $user->name }}</strong><small>{{ '@'.$user->username }}</small></span></td><td data-label="Role">{{ $user->role?->display_name ?? 'No role' }}</td><td data-label="Restaurant">@if($user->cloudRestaurant)<a href="{{ route('superadmin.restaurants', ['q' => $user->cloudRestaurant->name]) }}">{{ $user->cloudRestaurant->name }}</a>@else &mdash; @endif</td><td data-label="Status"><x-status :value="$user->trashed() ? 'deleted' : ($user->is_active ? 'active' : 'disabled')" /></td><td data-label="Email"><span class="table-primary"><strong>{{ $user->email ?: 'Not provided' }}</strong><small>{{ $user->email_verified_at ? 'Verified' : 'Not verified' }}</small></span></td><td data-label="Last login">{{ $user->last_login_at?->format('d M Y H:i') ?? 'Never' }}</td></tr>@empty<tr><td colspan="6">No users match these filters.</td></tr>@endforelse
</tbody></table></div><div class="card__body">@include('superadmin._pagination', ['records' => $users, 'label' => 'Users'])</div></section>
@endsection
