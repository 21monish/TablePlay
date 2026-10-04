@extends('layouts.app')
@section('title', 'Start free trial')

@section('content')
<section class="login-shell trial-shell">
    <div class="login-story trial-story">
        <div class="login-story__content">
            <x-brand light />
            <span class="eyebrow" style="display:block;margin-top:54px;color:#f5bd62">14-day free trial</span>
            <h1 style="margin-top:12px">See TablePlay work in your restaurant.</h1>
            <p>Register your restaurant, verify your email, and receive a secure activation key for one local server and up to three customer tablets.</p>
        </div>
        <div class="login-story__features"><span><i></i> No payment card</span><span><i></i> 3 tablets</span><span><i></i> Assisted setup</span></div>
    </div>
    <div class="login-panel trial-panel">
        <div class="login-panel__mobile-brand"><x-brand /></div>
        <span class="eyebrow">Restaurant onboarding</span>
        <h2>Start your free trial</h2>
        <p>Use real owner details so we can protect your licence and help with installation.</p>
        @if($errors->any())<div class="alert alert--danger" role="alert"><div>@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div></div>@endif
        <form method="post" action="{{ route('trial.store') }}" novalidate>@csrf
            <input class="trial-honeypot" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
            <div class="form-grid">
                <label class="field field--full">Restaurant name<input name="restaurant_name" value="{{ old('restaurant_name') }}" maxlength="255" autocomplete="organization" required autofocus></label>
                <label class="field">Owner name<input name="owner_name" value="{{ old('owner_name') }}" maxlength="255" autocomplete="name" required></label>
                <label class="field">City<input name="city" value="{{ old('city') }}" maxlength="120" autocomplete="address-level2" required></label>
                <label class="field">Owner email<input type="email" name="email" value="{{ old('email') }}" maxlength="255" autocomplete="email" required></label>
                <label class="field">Mobile with country code<input type="tel" name="mobile" value="{{ old('mobile', '+91') }}" maxlength="16" autocomplete="tel" inputmode="tel" required></label>
                <label class="field">Password<span class="secret-input"><input id="trial-password" type="password" name="password" minlength="8" maxlength="255" autocomplete="new-password" required><button type="button" data-secret-toggle aria-controls="trial-password" aria-label="Show password"><span data-secret-label>Show</span></button></span></label>
                <label class="field">Confirm password<span class="secret-input"><input id="trial-password-confirmation" type="password" name="password_confirmation" minlength="8" maxlength="255" autocomplete="new-password" required><button type="button" data-secret-toggle aria-controls="trial-password-confirmation" aria-label="Show password"><span data-secret-label>Show</span></button></span></label>
                <div class="trial-consent field--full">
                    <label class="check-row"><input type="checkbox" name="terms" value="1" @checked(old('terms')) required> I accept the <a href="{{ route('terms') }}" target="_blank" rel="noopener">Terms of Service</a>.</label>
                    <label class="check-row"><input type="checkbox" name="privacy" value="1" @checked(old('privacy')) required> I accept the <a href="{{ route('privacy') }}" target="_blank" rel="noopener">Privacy Policy</a>.</label>
                </div>
                <button class="button button--accent button--block field--full" type="submit">Create restaurant trial <x-icon name="arrow" :size="16" /></button>
            </div>
        </form>
        <div class="login-help"><span>Already registered? <a href="{{ route('login') }}">Sign in to TablePlay</a></span></div>
    </div>
</section>
@endsection
