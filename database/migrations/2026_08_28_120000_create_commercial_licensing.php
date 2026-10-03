<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commercial_plans', function (Blueprint $table) {
            $table->id(); $table->string('slug')->unique(); $table->string('name');
            $table->unsignedSmallInteger('trial_days')->default(0); $table->unsignedInteger('max_paired_tables')->nullable();
            $table->json('features'); $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('restaurant_subscriptions', function (Blueprint $table) {
            $table->id(); $table->foreignId('commercial_plan_id')->constrained()->restrictOnDelete();
            $table->uuid('installation_uuid')->unique(); $table->string('license_reference')->unique();
            $table->enum('status', ['trial','active','grace','suspended','expired'])->default('trial');
            $table->timestamp('starts_at'); $table->timestamp('expires_at')->nullable(); $table->timestamp('grace_ends_at')->nullable();
            $table->json('entitlement_snapshot'); $table->text('signed_payload'); $table->string('signature', 128);
            $table->timestamp('last_verified_at')->nullable(); $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('source', ['customer','waiter','counter'])->default('customer')->after('order_sequence');
            $table->foreignId('placed_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->uuid('client_request_id')->nullable()->unique()->after('placed_by');
        });

        $now = now();
        $plans = [
            ['trial','Free Trial',30,3,['staff_apps'=>true,'waiter_ordering'=>true,'customer_app'=>true,'games'=>true,'advanced_reports'=>false,'automation'=>false]],
            ['simple','Simple',0,0,['staff_apps'=>true,'waiter_ordering'=>true,'customer_app'=>false,'games'=>false,'advanced_reports'=>false,'automation'=>false]],
            ['best','Best',0,5,['staff_apps'=>true,'waiter_ordering'=>true,'customer_app'=>true,'games'=>true,'advanced_reports'=>false,'automation'=>false]],
            ['pro','Pro',0,10,['staff_apps'=>true,'waiter_ordering'=>true,'customer_app'=>true,'games'=>true,'advanced_reports'=>true,'automation'=>true]],
            ['premium','Premium',0,null,['staff_apps'=>true,'waiter_ordering'=>true,'customer_app'=>true,'games'=>true,'advanced_reports'=>true,'automation'=>true,'premium_support'=>true]],
        ];
        foreach ($plans as $index => [$slug,$name,$trialDays,$limit,$features]) DB::table('commercial_plans')->insert(['slug'=>$slug,'name'=>$name,'trial_days'=>$trialDays,'max_paired_tables'=>$limit,'features'=>json_encode($features),'sort_order'=>$index+1,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
        // A production trial is a commercial entitlement, not seed data. It is
        // issued once by TablePlay Cloud and bound to the restaurant and device.
        // Fresh/reinstalled databases therefore start unlicensed until a signed
        // online or offline licence is installed.
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('placed_by'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['source','client_request_id']));
        Schema::dropIfExists('restaurant_subscriptions'); Schema::dropIfExists('commercial_plans');
    }
};
