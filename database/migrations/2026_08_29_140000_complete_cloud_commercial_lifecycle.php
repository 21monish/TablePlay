<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commercial_plans', 'grace_days')) Schema::table('commercial_plans', fn (Blueprint $table) => $table->unsignedSmallInteger('grace_days')->default(7)->after('trial_days'));
        if (! Schema::hasColumn('cloud_subscriptions', 'scheduled_plan_id')) Schema::table('cloud_subscriptions', function (Blueprint $table) {
            $table->foreignId('scheduled_plan_id')->nullable()->after('commercial_plan_id')->constrained('commercial_plans')->nullOnDelete();
            $table->timestamp('scheduled_change_at')->nullable()->after('grace_ends_at');
            $table->unsignedSmallInteger('scheduled_duration_days')->nullable()->after('scheduled_change_at');
            $table->text('scheduled_reason')->nullable()->after('scheduled_duration_days');
        });
        if (! Schema::hasColumn('cloud_invoices', 'refunded_amount')) Schema::table('cloud_invoices', function (Blueprint $table) {
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('total');
            $table->timestamp('voided_at')->nullable()->after('paid_at');
        });
        if (! Schema::hasColumn('cloud_payments', 'status')) Schema::table('cloud_payments', function (Blueprint $table) {
            $table->enum('status', ['completed', 'refunded'])->default('completed')->after('payment_number');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
            $table->text('refund_reason')->nullable()->after('refunded_at');
        });
        if (! Schema::hasTable('cloud_renewal_reminders')) Schema::create('cloud_renewal_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloud_subscription_id')->constrained('cloud_subscriptions')->cascadeOnDelete();
            $table->unsignedSmallInteger('days_before_expiry');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['cloud_subscription_id', 'days_before_expiry', 'expires_at'], 'cloud_reminder_unique');
        });
        if (! Schema::hasTable('cloud_commercial_settings')) Schema::create('cloud_commercial_settings', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name')->default('TablePlay');
            $table->string('tax_number', 80)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('currency', 8)->default('INR');
            $table->string('invoice_prefix', 20)->default('TP-INV');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_commercial_settings');
        Schema::dropIfExists('cloud_renewal_reminders');
        Schema::table('cloud_payments', fn (Blueprint $table) => $table->dropColumn(['status','refunded_at','refund_reason']));
        Schema::table('cloud_invoices', fn (Blueprint $table) => $table->dropColumn(['refunded_amount','voided_at']));
        Schema::table('cloud_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_plan_id');
            $table->dropColumn(['scheduled_change_at','scheduled_duration_days','scheduled_reason']);
        });
        Schema::table('commercial_plans', fn (Blueprint $table) => $table->dropColumn('grace_days'));
    }
};
