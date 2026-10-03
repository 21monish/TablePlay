<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['installation_uuid']);
            $table->index('installation_uuid');
            $table->string('source', 20)->default('legacy')->after('status');
            $table->string('license_key_id', 80)->nullable()->after('signature');
            $table->text('verification_error')->nullable()->after('last_verified_at');
        });

        Schema::create('tableplay_installations', function (Blueprint $table) {
            $table->id();
            $table->uuid('installation_uuid')->unique();
            $table->string('device_fingerprint', 128)->nullable();
            $table->string('cloud_url')->nullable();
            $table->text('activation_token')->nullable();
            $table->string('license_reference')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('cloud_restaurants', function (Blueprint $table) {
            $table->id();
            $table->uuid('restaurant_uuid')->unique();
            $table->string('name');
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable()->index();
            $table->string('owner_phone', 40)->nullable();
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cloud_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloud_restaurant_id')->constrained('cloud_restaurants')->cascadeOnDelete();
            $table->foreignId('commercial_plan_id')->constrained('commercial_plans')->restrictOnDelete();
            $table->string('license_reference')->unique();
            $table->enum('status', ['trial', 'active', 'grace', 'suspended', 'expired'])->default('trial');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->unsignedSmallInteger('max_installations')->default(1);
            $table->timestamps();
        });

        Schema::create('cloud_license_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloud_subscription_id')->constrained('cloud_subscriptions')->cascadeOnDelete();
            $table->string('key_prefix', 16)->index();
            $table->char('key_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cloud_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloud_subscription_id')->constrained('cloud_subscriptions')->cascadeOnDelete();
            $table->uuid('installation_uuid')->unique();
            $table->string('device_name');
            $table->string('device_fingerprint', 128)->nullable();
            $table->char('activation_token_hash', 64)->unique();
            $table->enum('status', ['active', 'deactivated', 'transferred'])->default('active');
            $table->string('server_version', 40)->nullable();
            $table->ipAddress('last_ip_address')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedBigInteger('replacement_installation_id')->nullable();
            $table->timestamps();
        });

        Schema::create('license_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('installation_uuid')->nullable()->index();
            $table->string('event', 80);
            $table->enum('status', ['success', 'warning', 'failed']);
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('app_releases', function (Blueprint $table) {
            $table->string('channel', 20)->default('stable')->after('platform');
            $table->unsignedTinyInteger('rollout_percentage')->default(100)->after('channel');
            $table->index(['app', 'platform', 'channel', 'is_published'], 'app_release_channel_index');
        });

        Schema::table('app_installations', function (Blueprint $table) {
            $table->string('channel', 20)->default('stable')->after('platform');
        });

        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->string('receipt_printer_name')->nullable();
            $table->unsignedSmallInteger('receipt_paper_width')->default(80);
        });

        $existing = DB::table('restaurant_subscriptions')->latest('id')->first();
        DB::table('tableplay_installations')->insert([
            'installation_uuid' => $existing?->installation_uuid ?: (string) Str::uuid(),
            'license_reference' => $existing?->license_reference,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->dropColumn(['receipt_printer_name', 'receipt_paper_width']);
        });
        Schema::table('app_installations', fn (Blueprint $table) => $table->dropColumn('channel'));
        Schema::table('app_releases', function (Blueprint $table) {
            $table->dropIndex('app_release_channel_index');
            $table->dropColumn(['channel', 'rollout_percentage']);
        });
        Schema::dropIfExists('license_sync_logs');
        Schema::dropIfExists('cloud_installations');
        Schema::dropIfExists('cloud_license_keys');
        Schema::dropIfExists('cloud_subscriptions');
        Schema::dropIfExists('cloud_restaurants');
        Schema::dropIfExists('tableplay_installations');
        Schema::table('restaurant_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['installation_uuid']);
            $table->dropColumn(['source', 'license_key_id', 'verification_error']);
            $table->unique('installation_uuid');
        });
    }
};
