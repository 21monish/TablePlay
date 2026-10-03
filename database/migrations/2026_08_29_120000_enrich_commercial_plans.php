<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_plans', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->decimal('monthly_price', 10, 2)->nullable()->after('trial_days');
            $table->decimal('annual_price', 10, 2)->nullable()->after('monthly_price');
            $table->boolean('is_featured')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_plans', function (Blueprint $table) {
            $table->dropColumn(['description', 'monthly_price', 'annual_price', 'is_featured']);
        });
    }
};
