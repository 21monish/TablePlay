@php
    $platformGroups = [
        'Platform' => [
            ['Overview', 'dashboard', 'superadmin.overview', ['superadmin.overview']],
            ['Users', 'team', 'superadmin.users', ['superadmin.users']],
            ['Restaurants & profits', 'tables', 'superadmin.restaurants', ['superadmin.restaurants']],
        ],
        'Commercial' => [
            ['Plans & licences', 'license', 'superadmin.index', ['superadmin.index', 'superadmin.plans.*']],
            ['Customer billing', 'counter', 'superadmin.cloud.index', ['superadmin.cloud.index', 'superadmin.cloud.restaurants.index']],
            ['Invoices', 'receipt', 'superadmin.invoices', ['superadmin.invoices', 'superadmin.cloud.invoices.show']],
            ['Payments', 'order', 'superadmin.payments', ['superadmin.payments']],
            ['Renewals', 'clock', 'superadmin.renewals', ['superadmin.renewals']],
        ],
        'Operations' => [
            ['Server installations', 'device', 'superadmin.installations', ['superadmin.installations']],
            ['Offline activation', 'storage', 'superadmin.cloud.index', [], 'offline-activation'],
            ['Activity log', 'report', 'superadmin.activity', ['superadmin.activity']],
        ],
    ];
@endphp
@foreach($platformGroups as $group => $items)
<div class="sidebar-nav-group" role="group" aria-labelledby="platform-nav-{{ $loop->index }}">
    <span class="nav-label" id="platform-nav-{{ $loop->index }}">{{ $group }}</span>
    @foreach($items as $item)
        @php($current = count($item[3]) > 0 && request()->routeIs(...$item[3]))
        <a class="nav-link {{ $current ? 'active' : '' }}" href="{{ route($item[2]) }}{{ isset($item[4]) ? '#'.$item[4] : '' }}" @if($current) aria-current="page" @endif><x-icon :name="$item[1]" /><span>{{ $item[0] }}</span></a>
    @endforeach
</div>
@endforeach
