@extends('layouts.app')
@section('title', 'System health')
@section('eyebrow', 'Configuration')
@section('page-title', 'System health')

@section('content')
<div class="page-heading"><div><h2>Local server status</h2><p>Live diagnostics for the database, cache, storage, queue, real-time service, and registered devices.</p></div><div class="page-heading__actions"><button class="button button--secondary" type="button" data-health-refresh><x-icon name="refresh" :size="17" /> Run checks again</button></div></div>
<section class="card card--accent health-summary" data-health-summary><span class="health-ring {{ $health['overall'] }}"><x-icon name="health" :size="27" /></span><div><h2>{{ str($health['overall'])->title() }}</h2><p>Last checked {{ \Illuminate\Support\Carbon::parse($health['checked_at'])->diffForHumans() }} &middot; Updates do not expose this page outside authenticated Admin access.</p></div></section>
<div class="content-grid content-grid--2" style="margin-top:18px">
    <section class="card"><div class="card__header"><div><h2>Service checks</h2><p>Core components required for restaurant operation</p></div></div><div class="card__body health-grid" data-health-checks>@foreach($health['checks'] as $key=>$check)<article class="health-check health-check--{{ $check['status'] }}"><span class="health-check__icon"><x-icon :name="match($key){'database'=>'database','storage'=>'storage','queue'=>'order','reverb'=>'health',default=>'check'}" /></span><span class="health-check__copy"><strong>{{ $check['label'] }}</strong><small>{{ $check['message'] }}</small></span><x-status :value="$check['status']" /></article>@endforeach</div></section>
    <div class="stack">
        <section class="card"><div class="card__header"><div><h2>Runtime</h2><p>Current Laravel process configuration</p></div></div><div class="card__body quick-list">@foreach($health['runtime'] as $label=>$value)<div class="quick-list__item"><span class="muted">{{ str($label)->replace('_',' ')->title() }}</span><strong>{{ is_bool($value) ? ($value ? 'Enabled' : 'Disabled') : $value }}</strong></div>@endforeach</div></section>
        <section class="card"><div class="card__header"><div><h2>Device health</h2><p>Online means seen within {{ $health['devices']['threshold_minutes'] }} minutes</p></div></div><div class="card__body"><div class="device-metrics"><span><strong>{{ $health['devices']['registered'] }}</strong><small>Registered</small></span><span><strong>{{ $health['devices']['active'] }}</strong><small>Active</small></span><span><strong>{{ $health['devices']['online'] }}</strong><small>Online</small></span></div></div><div class="card__footer"><a class="button button--secondary button--small" href="{{ route('admin.tables') }}">Review devices <x-icon name="arrow" :size="14" /></a></div></section>
    </div>
</div>
<section class="card" style="margin-top:18px"><div class="card__header"><div><h2>Pilot checklist</h2><p>Quick operational checks before opening a table</p></div></div><div class="card__body checklist"><span><x-icon name="check" :size="17" /> Laptop and test phones use the same hotspot</span><span><x-icon name="check" :size="17" /> Laravel, Reverb, and queue worker are running</span><span><x-icon name="check" :size="17" /> Windows firewall rules are limited to the hotspot subnet</span><span><x-icon name="check" :size="17" /> Each phone is paired to a different table code</span></div></section>
@endsection

@push('scripts')
<script>
document.querySelector('[data-health-refresh]')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    button.setAttribute('aria-busy','true');
    try { const response = await fetch('{{ route('admin.system.health') }}',{headers:{Accept:'application/json'}}); if(!response.ok) throw new Error('Health request failed'); window.location.reload(); }
    catch(error){ button.removeAttribute('aria-busy'); window.TablePlay?.toast('Could not refresh system health. Check the Laravel log.','danger','Health check failed'); }
});
</script>
@endpush
