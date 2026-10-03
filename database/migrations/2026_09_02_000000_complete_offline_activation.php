<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tableplay_installations', function (Blueprint $table) {
            $table->text('offline_request_public_key')->nullable();
            $table->text('offline_request_private_key')->nullable();
            $table->uuid('offline_request_id')->nullable();
            $table->char('offline_request_hash', 64)->nullable();
            $table->timestamp('offline_request_generated_at')->nullable();
            $table->unsignedBigInteger('license_revision')->default(0);
            $table->char('license_content_hash', 64)->nullable();
            $table->timestamp('last_license_issued_at')->nullable();
        });

        Schema::table('cloud_installations', function (Blueprint $table) {
            $table->string('activation_method', 20)->default('online');
            $table->uuid('offline_request_id')->nullable()->unique();
            $table->char('offline_request_hash', 64)->nullable();
            $table->text('request_public_key')->nullable();
            $table->timestamp('request_generated_at')->nullable();
            $table->unsignedBigInteger('license_revision')->default(0);
        });

        Schema::table('cloud_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('license_revision')->default(1);
        });

        Schema::table('restaurant_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('license_revision')->default(0);
            $table->uuid('license_uuid')->nullable()->unique();
            $table->json('entitlement_limits')->nullable();
        });

        Schema::create('local_offline_activation_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->uuid('installation_uuid')->index();
            $table->char('request_hash', 64)->unique();
            $table->json('request_document');
            $table->string('status', 20)->default('pending')->index();
            $table->dateTime('generated_at');
            $table->dateTime('expires_at');
            $table->dateTime('fulfilled_at')->nullable();
            $table->unsignedBigInteger('fulfilled_revision')->nullable();
            $table->timestamps();
        });

        Schema::create('cloud_offline_activation_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->uuid('installation_uuid')->index();
            $table->foreignId('cloud_subscription_id')->nullable()->constrained('cloud_subscriptions')->nullOnDelete();
            $table->foreignId('cloud_installation_id')->nullable()->constrained('cloud_installations')->nullOnDelete();
            $table->string('device_name');
            $table->string('device_fingerprint', 128);
            $table->text('request_public_key');
            $table->string('server_version', 40)->nullable();
            $table->char('request_hash', 64)->unique();
            $table->json('request_payload');
            $table->string('status', 20)->default('imported')->index();
            $table->dateTime('generated_at');
            $table->dateTime('expires_at');
            $table->dateTime('imported_at');
            $table->dateTime('issued_at')->nullable();
            $table->unsignedBigInteger('license_revision')->nullable();
            $table->char('license_hash', 64)->nullable();
            $table->json('license_envelope')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_offline_activation_requests');
        Schema::dropIfExists('local_offline_activation_requests');
        Schema::table('restaurant_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['license_uuid']);
            $table->dropColumn(['license_revision', 'license_uuid', 'entitlement_limits']);
        });
        Schema::table('cloud_subscriptions', fn (Blueprint $table) => $table->dropColumn('license_revision'));
        Schema::table('cloud_installations', function (Blueprint $table) {
            $table->dropUnique(['offline_request_id']);
            $table->dropColumn(['activation_method', 'offline_request_id', 'offline_request_hash', 'request_public_key', 'request_generated_at', 'license_revision']);
        });
        Schema::table('tableplay_installations', function (Blueprint $table) {
            $table->dropColumn([
                'offline_request_public_key', 'offline_request_private_key', 'offline_request_id',
                'offline_request_hash', 'offline_request_generated_at', 'license_revision',
                'license_content_hash', 'last_license_issued_at',
            ]);
        });
    }
};
