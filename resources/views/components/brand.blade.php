@props(['compact' => false, 'light' => false])
<span {{ $attributes->class(['brand-lockup', 'brand-lockup--compact' => $compact, 'brand-lockup--light' => $light]) }}>
    <img class="brand-lockup__mark" src="{{ $appSettings?->app_logo_url ?: '/brand/tableplay-mark.svg' }}" alt="" width="42" height="42">
    @unless($compact)
        <span class="brand-lockup__copy">
            <strong>Table<span>Play</span></strong>
            <small>{{ $appSettings?->tagline ?: 'Dine. Play. Delight.' }}</small>
        </span>
    @endunless
</span>
