<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->string('secondary_color', 7)->default('#123c35')->after('brand_color');
            $table->string('restaurant_logo_path')->nullable()->after('secondary_color');
            $table->string('customer_app_logo_path')->nullable()->after('restaurant_logo_path');
            $table->string('staff_app_logo_path')->nullable()->after('customer_app_logo_path');
            $table->string('system_logo_path')->nullable()->after('staff_app_logo_path');
            $table->string('favicon_path')->nullable()->after('system_logo_path');
            $table->string('login_cover_path')->nullable()->after('favicon_path');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_settings', fn (Blueprint $table) => $table->dropColumn([
            'secondary_color', 'restaurant_logo_path', 'customer_app_logo_path',
            'staff_app_logo_path', 'system_logo_path', 'favicon_path', 'login_cover_path',
        ]));
    }
};
