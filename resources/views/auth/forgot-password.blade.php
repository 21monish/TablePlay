@extends('layouts.app')
@section('title', 'Recover your account')
@section('content')
<section class="card" style="max-width:540px;margin:6vh auto;padding:28px">
    <span class="eyebrow">Account recovery</span><h1>Forgot your password?</h1>
    <p>Enter the email registered to your account. You need access to that inbox and a working email service. For an account without email, ask your restaurant administrator.</p>
    @if(session('status'))<div class="alert" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert--danger" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('password.email') }}" class="stack">@csrf
        <label class="field">Account email<input name="email" type="email" maxlength="255" autocomplete="email" required value="{{ is_string(old('email')) ? old('email') : '' }}"></label>
        <button type="submit" class="button button--accent">Request reset link</button>
    </form><p><a href="{{ route('login') }}">Back to sign in</a></p>
</section>
@endsection
