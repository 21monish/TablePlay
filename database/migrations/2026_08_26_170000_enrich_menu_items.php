<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->string('short_description', 180)->nullable()->after('description');
            $table->text('ingredients')->nullable()->after('short_description');
            $table->json('allergens')->nullable()->after('ingredients');
            $table->enum('spice_level', ['none', 'mild', 'medium', 'hot'])->default('none')->after('allergens');
            $table->unsignedSmallInteger('calories')->nullable()->after('preparation_minutes');
            $table->decimal('discount_price', 12, 2)->nullable()->after('price');
            $table->json('customizations')->nullable()->after('discount_price');
            $table->boolean('is_recommended')->default(false)->after('is_available');
            $table->boolean('is_bestseller')->default(false)->after('is_recommended');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->json('selected_options')->nullable()->after('special_instruction');
            $table->decimal('options_amount', 12, 2)->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['selected_options', 'options_amount']));
        Schema::table('menu_items', fn (Blueprint $table) => $table->dropColumn([
            'short_description', 'ingredients', 'allergens', 'spice_level', 'calories',
            'discount_price', 'customizations', 'is_recommended', 'is_bestseller',
        ]));
    }
};
