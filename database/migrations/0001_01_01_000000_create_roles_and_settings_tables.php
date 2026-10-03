<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('name')->unique(); $table->string('display_name'); $table->timestamps();
        });
        Schema::create('restaurant_settings', function (Blueprint $table) {
            $table->id(); $table->string('restaurant_name'); $table->text('address')->nullable(); $table->string('phone')->nullable();
            $table->string('currency', 3)->default('INR'); $table->string('tax_name')->default('GST');
            $table->decimal('tax_rate', 5, 2)->default(0); $table->unsignedSmallInteger('game_duration_minutes')->default(60);
            $table->text('receipt_footer')->nullable(); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('restaurant_settings'); Schema::dropIfExists('roles'); }
};
