<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cloud_restaurant_financial_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloud_restaurant_id')->constrained('cloud_restaurants')->restrictOnDelete();
            $table->date('period_month')->index();
            $table->string('currency', 3);
            $table->decimal('net_sales', 12, 2);
            $table->decimal('expenses', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['cloud_restaurant_id', 'period_month'], 'restaurant_financial_month_unique');
        });

        Schema::table('cloud_payments', function (Blueprint $table) {
            $table->index('paid_at', 'platform_payments_paid_at_idx');
            $table->index('refunded_at', 'platform_payments_refunded_at_idx');
            $table->index(['cloud_invoice_id', 'status'], 'platform_payments_invoice_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Laravel's trusted backend owns access. Never expose financial reports
            // through Supabase's public Data API or user-editable JWT metadata.
            DB::statement('ALTER TABLE cloud_restaurant_financial_reports ENABLE ROW LEVEL SECURITY');
            DB::statement('REVOKE ALL ON cloud_restaurant_financial_reports FROM PUBLIC');
            DB::unprepared(<<<'SQL'
                DO $$ BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
                        REVOKE ALL ON cloud_restaurant_financial_reports FROM anon;
                    END IF;
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
                        REVOKE ALL ON cloud_restaurant_financial_reports FROM authenticated;
                    END IF;
                END; $$;
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cloud_restaurant_financial_reports');
        Schema::table('cloud_payments', function (Blueprint $table) {
            $table->dropIndex('platform_payments_paid_at_idx');
            $table->dropIndex('platform_payments_refunded_at_idx');
            $table->dropIndex('platform_payments_invoice_status_idx');
        });
    }
};
