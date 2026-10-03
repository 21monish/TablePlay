<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $games = [
            [
                'name' => 'Connect Four',
                'slug' => 'connect-four',
                'description' => 'Two-player strategy: connect four tokens in any direction.',
                'player_mode' => 'two',
                'game_path' => '/games/connect-four',
                'sort_order' => 6,
            ],
            [
                'name' => 'Table Race',
                'slug' => 'table-race',
                'description' => 'Four-player dice race around the table. First to 30 wins.',
                'player_mode' => 'four',
                'game_path' => '/games/table-race',
                'sort_order' => 7,
            ],
        ];

        foreach ($games as $game) {
            DB::table('games')->updateOrInsert(
                ['slug' => $game['slug']],
                $game + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('games')->whereIn('slug', ['connect-four', 'table-race'])->delete();
    }
};
