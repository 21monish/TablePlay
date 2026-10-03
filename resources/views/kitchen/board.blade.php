@extends('layouts.app')
@section('title', 'Kitchen display')
@section('eyebrow', 'Live operations')
@section('page-title', 'Kitchen display')

@section('content')
@php($late=$orders->filter(function($order){$prep=$order->items->max(fn($item)=>$item->menuItem?->preparation_minutes??15);return ($order->confirmed_at??$order->created_at)->addMinutes($prep)->isPast()&&$order->status!=='ready';})->count())
<div class="page-heading"><div><h2>Kitchen queue</h2><p>Tickets are ordered by confirmation time. Preparation targets turn red when overdue.</p></div><div class="page-heading__actions"><button class="button button--secondary" id="sound" type="button"><x-icon name="bell" :size="17" /> Enable sound</button><button class="button" id="fullscreen" type="button">Full screen</button></div></div>
<section class="stats-grid">
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="bell" /></span></div><div class="stat-card__value">{{ $orders->where('status','confirmed')->count() }}</div><div class="stat-card__label">New tickets</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="kitchen" /></span></div><div class="stat-card__value">{{ $orders->where('status','preparing')->count() }}</div><div class="stat-card__label">Preparing</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="check" /></span></div><div class="stat-card__value">{{ $orders->where('status','ready')->count() }}</div><div class="stat-card__label">Ready for service</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="clock" /></span></div><div class="stat-card__value {{ $late ? 'text-danger' : '' }}">{{ $late }}</div><div class="stat-card__label">Past prep target</div></article>
</section>

<section class="kitchen-board">
@forelse($orders as $order)
    @php($prep=$order->items->max(fn($item)=>$item->menuItem?->preparation_minutes??15))
    <article class="card kitchen-ticket kitchen-ticket--{{ $order->status }}">
        <div class="order-card__head"><div class="order-card__identity"><span class="table-code">{{ $order->tableSession->diningTable->table_code }}</span><span class="order-card__number"><strong>{{ $order->order_number }}</strong><small>{{ $order->items->sum('quantity') }} item(s) &middot; {{ ($order->confirmed_at??$order->created_at)->format('H:i') }}</small></span></div><div class="kitchen-ticket__timer timer" data-start="{{ ($order->confirmed_at??$order->created_at)->timestamp }}" data-minutes="{{ $prep }}"><strong>00:00</strong><small>{{ $prep }} min target</small></div></div>
        <div class="order-card__items">@foreach($order->items as $item)<div class="order-line"><span class="order-line__qty">{{ $item->quantity }}×</span><span class="order-line__name">{{ $item->item_name_snapshot }}</span>@if($item->special_instruction)<span class="order-line__note">{{ $item->special_instruction }}</span>@endif</div>@endforeach</div>
        @if($order->customer_note)<div class="order-card__note"><strong>Guest note:</strong> {{ $order->customer_note }}</div>@endif
        <div class="order-card__actions"><x-status :value="$order->status" />@if($order->status==='confirmed')<form method="post" action="{{ route('kitchen.orders.preparing',$order) }}">@csrf<button class="button button--accent" type="submit">Accept & start preparing</button></form>@elseif($order->status==='preparing')<form method="post" action="{{ route('kitchen.orders.ready',$order) }}">@csrf<button class="button" type="submit"><x-icon name="check" :size="16" /> Mark ready</button></form>@elseif($order->status==='ready')<form method="post" action="{{ route('kitchen.orders.served',$order) }}">@csrf<button class="button" type="submit">Mark served</button></form>@endif</div>
    </article>
@empty
    <article class="card" style="grid-column:1/-1"><x-empty-state icon="check" title="Kitchen queue is clear" message="New confirmed orders will appear automatically. Keep this screen open with sound enabled." /></article>
@endforelse
</section>
@endsection

@push('scripts')
<script>
let fingerprint=@json($orders->map(fn($order)=>[$order->id,$order->status,$order->updated_at?->timestamp]));
let sound=false;
const soundButton=document.querySelector('#sound');
function beep(){if(!sound)return;const context=new(window.AudioContext||window.webkitAudioContext)(),oscillator=context.createOscillator(),gain=context.createGain();oscillator.frequency.value=880;oscillator.connect(gain);gain.connect(context.destination);gain.gain.setValueAtTime(.18,context.currentTime);gain.gain.exponentialRampToValueAtTime(.001,context.currentTime+.45);oscillator.start();oscillator.stop(context.currentTime+.45);}
soundButton?.addEventListener('click',()=>{sound=true;beep();soundButton.innerHTML='Sound enabled';soundButton.classList.add('button--accent');});
document.querySelector('#fullscreen')?.addEventListener('click',()=>document.documentElement.requestFullscreen?.());
function timers(){document.querySelectorAll('.timer').forEach(el=>{const elapsed=Math.floor(Date.now()/1000)-Number(el.dataset.start),target=Number(el.dataset.minutes)*60,remaining=target-elapsed,late=remaining<0,value=Math.abs(remaining);el.querySelector('strong').textContent=(late?'+':'')+String(Math.floor(value/60)).padStart(2,'0')+':'+String(value%60).padStart(2,'0');el.classList.toggle('is-late',late);});}
timers();setInterval(timers,1000);
setInterval(async()=>{try{const response=await fetch('{{ route('kitchen.snapshot') }}',{headers:{Accept:'application/json'}});if(!response.ok)return;const data=await response.json(),next=JSON.stringify(data),old=JSON.stringify(fingerprint);if(next!==old){if(data.length>fingerprint.length)beep();window.location.reload();}}catch(error){}},{{ ($appSettings?->kitchen_refresh_seconds ?: 4)*1000 }});
</script>
@endpush
