@extends('layouts.app')
@section('title', 'Team')
@section('eyebrow', 'Administration')
@section('page-title', 'Team & access')

@section('content')
@php($editing = isset($editUser))
<div class="page-heading"><div><h2>Staff access control</h2><p>Create role-based accounts for the counter, kitchen, waiters, and administrators.</p></div></div>
<div class="content-grid content-grid--2">
    <section class="card">
        <div class="card__header"><div><h2>Team directory</h2><p>{{ $users->where('is_active',true)->count() }} active of {{ $users->count() }} accounts</p></div></div>
        <div class="table-wrap"><table class="data-table data-table--responsive"><thead><tr><th>Staff member</th><th>Role</th><th>Last login</th><th>Status</th><th>Action</th></tr></thead><tbody>
        @foreach($users as $user)
            <tr>
                <td><span class="table-primary"><strong>{{ $user->name }}</strong><small>{{ '@'.$user->username }}{{ $user->mobile ? ' · '.$user->mobile : '' }}</small></span></td>
                <td data-label="Role">{{ $user->role->display_name }}</td>
                <td data-label="Last login">{{ $user->last_login_at?->diffForHumans() ?: 'Never' }}</td>
                <td data-label="Status"><x-status :value="$user->is_active ? 'active' : 'disabled'" /></td>
                <td data-label="Action"><div class="table-actions" style="display:flex;gap:8px;flex-wrap:wrap">
                    <a class="button button--small" href="{{ route('admin.staff.edit',$user) }}" aria-label="Edit {{ $user->name }}">Edit</a>
                    @if(!$user->is(auth()->user()))
                        <form method="post" action="{{ route('admin.staff.toggle',$user) }}">@csrf<button class="button button--secondary button--small" data-confirm="{{ $user->is_active ? 'Disable' : 'Enable' }} {{ $user->name }}'s account?" data-confirm-tone="{{ $user->is_active ? 'danger' : 'default' }}">{{ $user->is_active ? 'Disable' : 'Enable' }}</button></form>
                        <form method="post" action="{{ route('admin.staff.destroy',$user) }}">@csrf @method('DELETE')
                            <button class="button button--danger button--small" type="submit" aria-label="Delete {{ $user->name }}"
                                data-confirm="Delete &quot;{{ $user->name }}&quot;'s staff account? Sign-in access will be removed. Past orders, bills and audit records will be kept."
                                data-confirm-title="Delete staff account?" data-confirm-button="Delete account" data-confirm-tone="danger">Delete</button>
                        </form>
                    @else<span class="subtle">Current account</span>@endif
                </div></td>
            </tr>
        @endforeach
        </tbody></table></div>
    </section>
    <section class="card">
        <div class="card__header"><div><h2>{{ $editing ? 'Edit staff member' : 'Add staff member' }}</h2><p>{{ $editing ? 'Leave password and PIN blank to keep them unchanged.' : 'Passwords require at least eight characters; PINs contain 4–12 digits.' }}</p></div><span class="stat-card__icon"><x-icon name="team" /></span></div>
        <form class="card__body" method="post" action="{{ $editing ? route('admin.staff.update',$editUser) : route('admin.staff.store') }}">@csrf
            @if($editing) @method('PUT') @endif
            <div class="form-grid">
                <label class="field">Full name<input name="name" value="{{ old('name',$editUser->name ?? '') }}" autocomplete="name" maxlength="255" required></label>
                <label class="field">Username<input name="username" value="{{ old('username',$editUser->username ?? '') }}" autocomplete="off" minlength="3" maxlength="100" pattern="[A-Za-z0-9._-]+" required></label>
                <label class="field">Role<select name="role_id" required>@foreach($roles as $role)<option value="{{ $role->id }}" @selected(old('role_id',$editUser->role_id ?? null)==$role->id)>{{ $role->display_name }}</option>@endforeach</select></label>
                <label class="field">Mobile<input name="mobile" value="{{ old('mobile',$editUser->mobile ?? '') }}" inputmode="tel" maxlength="30"></label>
                <label class="field field--full">Email<input type="email" name="email" value="{{ old('email',$editUser->email ?? '') }}" autocomplete="email" maxlength="255"></label>
                <label class="field">{{ $editing ? 'New password' : 'Password' }}<span class="secret-input"><input id="staff-password" type="password" name="password" minlength="8" maxlength="255" autocomplete="new-password" @required(!$editing)><button type="button" data-secret-toggle aria-controls="staff-password" aria-label="Show password" title="Show password"><span data-secret-label>Show</span></button></span></label>
                <label class="field">Quick PIN<span class="secret-input"><input id="staff-pin" type="password" name="pin" inputmode="numeric" minlength="4" maxlength="12" pattern="[0-9]{4,12}" autocomplete="off" placeholder="Optional 4–12 digits"><button type="button" data-secret-toggle aria-controls="staff-pin" aria-label="Show PIN" title="Show PIN"><span data-secret-label>Show</span></button></span></label>
                @if($editing)<label class="check"><input type="checkbox" name="clear_pin" value="1" @checked(old('clear_pin'))> Remove saved PIN</label>@endif
            </div>
            <div class="form-actions">@if($editing)<a class="button button--secondary" href="{{ route('admin.team') }}">Cancel</a>@endif<button class="button" type="submit"><x-icon :name="$editing ? 'check' : 'plus'" :size="16" /> {{ $editing ? 'Save changes' : 'Create account' }}</button></div>
            @if($editing)<p class="subtle">Deleted accounts retain their username and email for historical records. Use Disable for temporary access changes.</p>@endif
        </form>
    </section>
</div>
@endsection
