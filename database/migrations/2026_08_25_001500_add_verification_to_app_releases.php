<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_releases', function (Blueprint $table) {
            $table->string('package_identifier')->nullable()->after('mime_type');
            $table->char('signing_certificate_sha256', 64)->nullable()->after('sha256');
            $table->timestamp('verified_at')->nullable()->after('signing_certificate_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('app_releases', function (Blueprint $table) {
            $table->dropColumn([
                'package_identifier',
                'signing_certificate_sha256',
                'verified_at',
            ]);
        });
    }
};
