<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            ['name' => 'restaurant_owner'],
            ['display_name' => 'Restaurant Owner', 'created_at' => now(), 'updated_at' => now()],
        );

        Schema::table('cloud_restaurants', function (Blueprint $table): void {
            $table->foreignId('owner_user_id')->nullable()->unique()->after('id')->constrained('users')->nullOnDelete();
            $table->string('city', 120)->nullable()->after('owner_phone');
            $table->timestamp('trial_registered_at')->nullable()->after('status');
            $table->timestamp('terms_accepted_at')->nullable()->after('trial_registered_at');
            $table->timestamp('privacy_accepted_at')->nullable()->after('terms_accepted_at');
            $table->timestamp('welcome_email_sent_at')->nullable()->after('privacy_accepted_at');
        });

        DB::table('commercial_plans')->where('slug', 'trial')->update([
            'trial_days' => 14,
            'max_paired_tables' => 3,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('commercial_plans')->where('slug', 'trial')->update([
            'trial_days' => 30,
            'max_paired_tables' => 3,
            'updated_at' => now(),
        ]);

        Schema::table('cloud_restaurants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn(['city', 'trial_registered_at', 'terms_accepted_at', 'privacy_accepted_at', 'welcome_email_sent_at']);
        });

        $roleId = DB::table('roles')->where('name', 'restaurant_owner')->value('id');
        if ($roleId && ! DB::table('users')->where('role_id', $roleId)->exists()) {
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }
};
