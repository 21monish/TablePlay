@extends('layouts.app')
@section('title', 'Staff sign in')

@section('content')
<section class="login-shell">
    <div class="login-story">
        <div class="login-story__content"><x-brand light /><h1>Every table.<br>One smooth service.</h1><p>Orders, kitchen flow, guest requests, billing, and game access—coordinated locally even without internet.</p></div>
        <div class="login-story__features"><span><i></i> Local-first</span><span><i></i> Real-time</span><span><i></i> Role protected</span></div>
    </div>
    <div class="login-panel">
        <div class="login-panel__mobile-brand"><x-brand /></div>
        <span class="eyebrow">Secure staff access</span>
        <h2>Welcome back</h2>
        <p>Sign in with your staff password or assigned PIN.</p>
        @if($errors->any())<div class="alert alert--danger" role="alert"><div>@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div></div>@endif
        <form method="post" action="{{ route('login.store') }}">@csrf
            <div class="stack">
                <label class="field">Email or username<input name="username" value="{{ old('username') }}" autocomplete="username" autofocus required placeholder="e.g. admin@example.com or counter"></label>
                <label class="field">Password or PIN<span class="secret-input"><input id="login-credential" type="password" name="credential" autocomplete="current-password" required placeholder="Enter your credential"><button type="button" data-secret-toggle aria-controls="login-credential" aria-label="Show password or PIN" title="Show password or PIN"><span data-secret-label>Show</span></button></span></label>
                <label class="check-row"><input type="checkbox" name="remember" value="1" checked disabled> Keep me signed in until I log out</label>
                <button class="button button--accent button--block" type="submit">Sign in securely <x-icon name="arrow" :size="16" /></button>
            </div>
        </form>
        <div class="login-help"><x-icon name="health" :size="15" /><span>No internet connection is required on the restaurant network.</span></div>
    </div>
</section>
@endsection
