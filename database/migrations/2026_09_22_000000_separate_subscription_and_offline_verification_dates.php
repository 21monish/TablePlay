<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurant_subscriptions', function (Blueprint $table) {
            $table->timestamp('subscription_expires_at')->nullable()->after('expires_at');
            $table->timestamp('offline_verification_due_at')->nullable()->after('subscription_expires_at');
        });

        DB::table('restaurant_subscriptions')->orderBy('id')->each(function ($subscription) {
            DB::table('restaurant_subscriptions')->where('id', $subscription->id)->update([
                'subscription_expires_at' => $subscription->expires_at,
                // Older offline licences used expires_at as their verification
                // lease. Preserve that fail-closed behavior during upgrade.
                'offline_verification_due_at' => $subscription->source === 'offline'
                    ? $subscription->expires_at
                    : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['subscription_expires_at', 'offline_verification_due_at']);
        });
    }
};
