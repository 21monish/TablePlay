<?php

namespace App\Services;

use App\Models\{AppRelease, DevicePairing, DiningTable, Order, RestaurantSetting, User};

class SetupWizardService
{
    public function __construct(private SystemHealthService $health) {}

    public function snapshot(): array
    {
        $settings = RestaurantSetting::firstOrFail();
        if (! $settings->setup_started_at) $settings->update(['setup_started_at' => now()]);
        $health = $this->health->snapshot();
        $staffRoles = User::where('is_active', true)->whereHas('role', fn ($q) => $q->whereIn('name', ['counter', 'kitchen', 'waiter']))->count();
        $apps = AppRelease::where('is_published', true)->where(fn ($q) => $q
            ->where(fn ($x) => $x->where('app', 'customer')->where('platform', 'android'))
            ->orWhere(fn ($x) => $x->where('app', 'staff')->where('platform', 'windows')))->count();
        $steps = [
            ['key' => 'identity', 'label' => 'Restaurant identity', 'complete' => filled($settings->restaurant_name) && $settings->restaurant_name !== 'TablePlay Restaurant'],
            ['key' => 'health', 'label' => 'Server health', 'complete' => $health['overall'] !== 'unhealthy'],
            ['key' => 'tables', 'label' => 'Dining tables', 'complete' => DiningTable::where('is_active', true)->exists()],
            ['key' => 'staff', 'label' => 'Staff accounts', 'complete' => $staffRoles >= 3],
            ['key' => 'apps', 'label' => 'Applications', 'complete' => $apps >= 2],
            ['key' => 'pairing', 'label' => 'First tablet', 'complete' => DevicePairing::where('is_active', true)->exists()],
            ['key' => 'test', 'label' => 'Test order', 'complete' => Order::exists()],
        ];
        $completed = collect($steps)->where('complete', true)->count();
        return compact('settings', 'health', 'steps', 'completed') + [
            'total' => count($steps), 'percent' => (int) round($completed / count($steps) * 100),
            'tables' => DiningTable::with(['pairings' => fn ($q) => $q->where('is_active', true)])->where('is_active', true)->orderBy('table_code')->get(),
            'roles' => \App\Models\Role::whereIn('name', ['counter', 'kitchen', 'waiter'])->orderBy('name')->get(),
            'staff' => User::with('role')->whereHas('role', fn ($q) => $q->whereIn('name', ['counter', 'kitchen', 'waiter']))->get(),
            'releases' => AppRelease::where('is_published', true)->get()->keyBy(fn ($r) => $r->app.'-'.$r->platform),
            'ready' => collect($steps)->whereIn('key', ['identity', 'health', 'tables', 'staff', 'apps'])->every('complete'),
        ];
    }
}
