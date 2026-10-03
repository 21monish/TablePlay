<?php

namespace App\Providers;

use App\Models\RestaurantSetting;
use App\Services\EntitlementService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $settings = null;

        try {
            if (Schema::hasTable('restaurant_settings')) {
                $settings = RestaurantSetting::query()->first();
            }
        } catch (Throwable) {
            // Keep installation and migration commands usable before the database is ready.
        }

        if ($settings?->timezone) {
            config(['app.timezone' => $settings->timezone]);
            date_default_timezone_set($settings->timezone);
        }

        View::share('appSettings', $settings);

        $licenseState = null;
        try {
            if (Schema::hasTable('restaurant_subscriptions')) $licenseState = app(EntitlementService::class)->state();
        } catch (Throwable) {
            // Keep setup, migrations and disaster recovery screens available without licensing tables.
        }
        View::share('licenseState', $licenseState);
    }
}
