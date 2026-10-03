<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->timestamp('setup_started_at')->nullable();
            $table->timestamp('setup_completed_at')->nullable();
        });
        Schema::create('pairing_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('used_by_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['dining_table_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_tokens');
        Schema::table('restaurant_settings', fn (Blueprint $table) => $table->dropColumn(['setup_started_at', 'setup_completed_at']));
    }
};
