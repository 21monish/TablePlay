@props(['icon' => 'check', 'title', 'message' => null])
<div {{ $attributes->class('empty-state') }}>
    <span class="empty-state__icon"><x-icon :name="$icon" :size="24" /></span>
    <strong>{{ $title }}</strong>
    @if($message)<p>{{ $message }}</p>@endif
    {{ $slot }}
</div>
