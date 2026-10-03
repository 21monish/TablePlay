@extends('layouts.app')
@section('title', 'Verify email')
@section('eyebrow', 'Account security')
@section('page-title', 'Verify your email')

@section('content')
<section class="card" style="max-width:760px;margin:0 auto">
    <div class="card__header">
        <div>
            <span class="eyebrow">One final security step</span>
            <h2>Check your inbox</h2>
            <p>We sent the verification link to <strong>{{ $user->email }}</strong>. Open it in this browser to activate privileged access.</p>
        </div>
        <span class="stat-card__icon"><x-icon name="team" /></span>
    </div>
    <div class="card__body stack">
        <p class="subtle">The link is signed and expires automatically. If it is missing, check spam or request a new one below.</p>
        <form method="post" action="{{ route('verification.send') }}">
            @csrf
            <button class="button" type="submit">Send another verification email</button>
        </form>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="button button--secondary" type="submit">Sign out</button>
        </form>
    </div>
</section>
@endsection
