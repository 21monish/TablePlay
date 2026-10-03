<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->boolean('automation_enabled')->default(true);
            $table->boolean('auto_backup_enabled')->default(true);
            $table->string('backup_time', 5)->default('02:00');
            $table->unsignedSmallInteger('backup_retention_days')->default(14);
            $table->unsignedSmallInteger('pending_order_alert_minutes')->default(5);
            $table->unsignedSmallInteger('service_request_alert_minutes')->default(3);
            $table->timestamp('last_automation_at')->nullable();
            $table->timestamp('last_backup_at')->nullable();
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('status', 20)->default('running');
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->dropColumn([
                'automation_enabled', 'auto_backup_enabled', 'backup_time',
                'backup_retention_days', 'pending_order_alert_minutes',
                'service_request_alert_minutes', 'last_automation_at', 'last_backup_at',
            ]);
        });
    }
};
