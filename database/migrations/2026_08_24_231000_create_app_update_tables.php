<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->enum('app', ['staff', 'customer']);
            $table->enum('platform', ['android', 'windows']);
            $table->string('version', 40);
            $table->unsignedInteger('build_number')->nullable();
            $table->text('release_notes')->nullable();
            $table->boolean('mandatory')->default(false);
            $table->string('minimum_supported_version', 40)->nullable();
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['app', 'platform', 'version']);
            $table->index(['app', 'platform', 'is_published']);
        });

        Schema::create('app_installations', function (Blueprint $table) {
            $table->id();
            $table->enum('app', ['staff', 'customer']);
            $table->enum('platform', ['android', 'windows']);
            $table->uuid('installation_uuid');
            $table->string('device_name');
            $table->string('current_version', 40);
            $table->unsignedInteger('build_number')->nullable();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->ipAddress('ip_address')->nullable();
            $table->boolean('update_available')->default(false);
            $table->timestamp('last_checked_at');
            $table->timestamps();

            $table->unique(['app', 'platform', 'installation_uuid'], 'app_installation_identity');
            $table->index(['last_checked_at', 'app', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_installations');
        Schema::dropIfExists('app_releases');
    }
};
