<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Older MariaDB implicitly adds ON UPDATE CURRENT_TIMESTAMP to the
        // first non-null TIMESTAMP. A sync must never move a signed start date.
        foreach (['restaurant_subscriptions', 'cloud_subscriptions'] as $name) {
            if (Schema::hasTable($name)) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dateTime('starts_at')->change();
                });
            }
        }
    }

    public function down(): void
    {
        // Keep the safe, data-compatible type on rollback. Restoring the old
        // implicit timestamp behavior would corrupt authenticated licences.
    }
};
