@extends('layouts.app')
@section('title', 'Choose a new password')
@section('content')
<section class="card" style="max-width:540px;margin:6vh auto;padding:28px">
    <span class="eyebrow">Account security</span><h1>Choose a new password</h1>
    <p>Use 12–72 characters with uppercase, lowercase, a number and a symbol (maximum 72 bytes). An assigned staff PIN is not changed by a password reset.</p>
    @if($errors->any())<div class="alert alert--danger" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    <form method="post" action="{{ route('password.update') }}" class="stack">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="field">Account email<input name="email" type="email" maxlength="255" autocomplete="email" required value="{{ is_string(old('email', $email)) ? old('email', $email) : '' }}"></label>
        <label class="field">New password<span class="secret-input"><input id="reset-password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><button type="button" data-secret-toggle aria-controls="reset-password" aria-label="Show password"><span data-secret-label>Show</span></button></span></label>
        <label class="field">Confirm new password<span class="secret-input"><input id="reset-confirm" name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><button type="button" data-secret-toggle aria-controls="reset-confirm" aria-label="Show confirmation password"><span data-secret-label>Show</span></button></span></label>
        <button type="submit" class="button button--accent">Save new password</button>
    </form><p><a href="{{ route('password.request') }}">Request another reset link</a></p>
</section>
@endsection
