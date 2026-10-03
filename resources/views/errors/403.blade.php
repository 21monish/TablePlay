@extends('layouts.app')

@section('title', 'Access unavailable')
@section('eyebrow', 'TablePlay access control')
@section('page-title', 'This feature is unavailable')

@section('content')
@php
    $message = trim((string) ($exception?->getMessage() ?: 'Your account or current plan does not allow this action.'));
    $isPlanRestriction = str_contains(strtolower($message), 'plan') || str_contains(strtolower($message), 'licence');
    $homeRoute = ! auth()->check()
        ? route('login')
        : (auth()->user()->hasRole('superadmin')
            ? route('superadmin.index')
            : (auth()->user()->hasRole('admin') ? route('admin.overview') : (auth()->user()->hasRole('kitchen') ? route('kitchen.index') : route('counter.index'))));
@endphp
<section class="card access-denied-card">
    <div class="card__body">
        <span class="access-denied-card__icon"><x-icon name="license" :size="28" /></span>
        <span class="eyebrow">HTTP 403 &middot; Protected workspace</span>
        <h2>{{ $isPlanRestriction ? 'Your plan does not include this feature' : 'You do not have access to this area' }}</h2>
        <p>{{ $message }}</p>
        <div class="form-actions">
            <a class="button button--secondary" href="{{ $homeRoute }}">Return to workspace</a>
            @if($isPlanRestriction && auth()->user()?->hasRole('admin'))
                <a class="button" href="{{ route('admin.license.index') }}">View licence and plan</a>
            @endif
        </div>
    </div>
</section>
@endsection
