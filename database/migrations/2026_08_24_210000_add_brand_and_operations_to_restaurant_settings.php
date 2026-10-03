<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->string('tagline', 120)->nullable()->after('restaurant_name');
            $table->string('email')->nullable()->after('phone');
            $table->string('gstin', 30)->nullable()->after('email');
            $table->string('brand_color', 7)->default('#ef6a3a')->after('gstin');
            $table->string('timezone', 64)->default('Asia/Kolkata')->after('brand_color');
            $table->unsignedTinyInteger('kitchen_refresh_seconds')->default(4)->after('game_duration_minutes');
            $table->unsignedTinyInteger('device_offline_minutes')->default(5)->after('kitchen_refresh_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'email', 'gstin', 'brand_color', 'timezone', 'kitchen_refresh_seconds', 'device_offline_minutes']);
        });
    }
};
