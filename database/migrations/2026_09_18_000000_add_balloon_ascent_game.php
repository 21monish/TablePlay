<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('games')->where('slug', 'balloon-ascent')->exists();

        if (! $exists) {
            DB::table('games')->where('sort_order', '>=', 5)->increment('sort_order');
        }

        DB::table('games')->updateOrInsert(
            ['slug' => 'balloon-ascent'],
            [
                'name' => 'Balloon Ascent',
                'description' => 'Steer through spike gates, collect bubbles, and use sky power-ups.',
                'player_mode' => 'one',
                'game_path' => '/games/balloon-ascent',
                'thumbnail_path' => null,
                'sort_order' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $deleted = DB::table('games')->where('slug', 'balloon-ascent')->delete();

        if ($deleted) {
            DB::table('games')->where('sort_order', '>', 5)->decrement('sort_order');
        }
    }
};
