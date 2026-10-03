@props(['name', 'size' => 20])
<svg {{ $attributes->class('ui-icon') }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
    @case('update')<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>@break
    @case('license')<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M7 10h6M7 14h4"/><circle cx="17" cy="12" r="2"/>@break
    @case('download')<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>@break
    @case('dashboard')<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>@break
    @case('tables')<rect x="4" y="7" width="16" height="10" rx="3"/><path d="M8 7V4m8 3V4M8 17v3m8-3v3"/>@break
    @case('menu')<path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M9 8h6M9 12h6M9 16h4"/>@break
    @case('team')<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>@break
    @case('game')<path d="M7 6h10a5 5 0 0 1 4.8 6.4l-1.2 4A3.6 3.6 0 0 1 14 17.5l-1-1h-2l-1 1a3.6 3.6 0 0 1-6.6-1.1l-1.2-4A5 5 0 0 1 7 6Z"/><path d="M7 11h4M9 9v4"/><circle cx="16" cy="10" r=".7" fill="currentColor"/><circle cx="18" cy="12" r=".7" fill="currentColor"/>@break
    @case('report')<path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/>@break
    @case('settings')<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3a2 2 0 1 1 4 0v.1A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.14.37.36.7.65.96.3.27.68.42 1.08.44H21a2 2 0 1 1 0 4h-.1A1.7 1.7 0 0 0 19.4 15Z"/>@break
    @case('health')<path d="M3 12h4l2-7 4 14 2-7h6"/>@break
    @case('counter')<path d="M4 10h16l-1 10H5L4 10Z"/><path d="M8 10V7a4 4 0 0 1 8 0v3M3 20h18"/>@break
    @case('kitchen')<path d="M6 3v8m3-8v8M6 7h3m-1.5 4v10M17 3c-2 2-3 4.2-3 6.5A3.5 3.5 0 0 0 17.5 13H19V3h-2Zm.5 10v8"/>@break
    @case('logout')<path d="M10 17l5-5-5-5M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>@break
    @case('moon')<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/>@break
    @case('sun')<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>@break
    @case('bell')<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>@break
    @case('menu-toggle')<path d="M4 7h16M4 12h16M4 17h16"/>@break
    @case('close')<path d="M18 6 6 18M6 6l12 12"/>@break
    @case('plus')<path d="M12 5v14M5 12h14"/>@break
    @case('arrow')<path d="m9 18 6-6-6-6"/>@break
    @case('check')<path d="m5 12 4 4L19 6"/>@break
    @case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
    @case('receipt')<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6m-6 4h6m-6 4h4"/>@break
    @case('device')<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/>@break
    @case('order')<path d="M6 3h12v18H6zM9 8h6m-6 4h6m-6 4h3"/>@break
    @case('refresh')<path d="M20 11a8 8 0 1 0 2 5M20 4v7h-7"/>@break
    @case('database')<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>@break
    @case('automation')<path d="M4 7h10M4 17h16M18 7h2"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/><path d="M12 3v2m0 14v2"/>@break
    @case('setup')<path d="m12 3 2.3 4.7L19.5 9l-3.8 3.7.9 5.3-4.6-2.5L7.4 18l.9-5.3L4.5 9l5.2-1.3L12 3Z"/><path d="m9.5 11.5 1.7 1.7 3.4-3.4"/>@break
    @case('storage')<path d="M3 7h18v12H3zM3 7l3-3h12l3 3M9 11h6"/>@break
    @case('help')<circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.5 2.5 0 1 1 3.7 2.2c-.9.5-1.4 1-1.4 2.1M12 17h.01"/>@break
    @case('chat')<path d="M21 14a4 4 0 0 1-4 4H9l-5 3v-7a4 4 0 0 1-2-3.5V7a4 4 0 0 1 4-4h11a4 4 0 0 1 4 4v7Z"/><path d="M7 9h10M7 13h6"/>@break
    @case('send')<path d="m22 2-7 20-4-9-9-4 20-7Z"/><path d="M22 2 11 13"/>@break
    @case('bot')<rect x="4" y="7" width="16" height="13" rx="4"/><path d="M9 12h.01M15 12h.01M9 16h6M12 7V4m-2 0h4"/>@break
    @default<circle cx="12" cy="12" r="9"/>@break
@endswitch
</svg>
