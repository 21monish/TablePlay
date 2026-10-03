<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('games')->updateOrInsert(
            ['slug' => 'snake'],
            [
                'name' => 'TablePlay Snake',
                'description' => 'Guide the snake, collect bites, and set the table high score.',
                'player_mode' => 'one',
                'game_path' => '/games/snake',
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('games')->where('slug', 'snake')->delete();
    }
};
