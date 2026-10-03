<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cloud_restaurants', function (Blueprint $table) {
            $table->string('billing_name')->nullable()->after('owner_phone');
            $table->string('billing_email')->nullable()->after('billing_name');
            $table->string('tax_number', 80)->nullable()->after('billing_email');
            $table->text('billing_address')->nullable()->after('tax_number');
        });

        Schema::create('cloud_subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloud_subscription_id')->constrained('cloud_subscriptions')->cascadeOnDelete();
            $table->string('event', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('from_plan_id')->nullable()->constrained('commercial_plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->nullable()->constrained('commercial_plans')->nullOnDelete();
            $table->timestamp('effective_at');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cloud_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloud_restaurant_id')->constrained('cloud_restaurants')->restrictOnDelete();
            $table->foreignId('cloud_subscription_id')->nullable()->constrained('cloud_subscriptions')->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->enum('status', ['draft', 'issued', 'paid', 'overdue', 'void'])->default('issued');
            $table->string('currency', 8)->default('INR');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cloud_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloud_restaurant_id')->constrained('cloud_restaurants')->restrictOnDelete();
            $table->foreignId('cloud_invoice_id')->nullable()->constrained('cloud_invoices')->nullOnDelete();
            $table->string('payment_number')->unique();
            $table->enum('method', ['cash', 'bank', 'upi', 'card', 'other']);
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable();
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_payments');
        Schema::dropIfExists('cloud_invoices');
        Schema::dropIfExists('cloud_subscription_events');
        Schema::table('cloud_restaurants', fn (Blueprint $table) => $table->dropColumn(['billing_name', 'billing_email', 'tax_number', 'billing_address']));
    }
};
