<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $existing = DB::table('games')->where('slug', 'word-scramble')->first();
        $dinosaur = DB::table('games')->where('slug', 'dinosaur-dash')->first();

        if ($existing && $dinosaur && $existing->id !== $dinosaur->id) {
            DB::table('games')->where('id', $existing->id)->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);
            DB::table('games')->where('id', $dinosaur->id)->update([
                'name' => 'Dinosaur Dash',
                'description' => 'Run, jump over obstacles, and chase the table high score.',
                'player_mode' => 'one',
                'game_path' => '/games/dinosaur-dash',
                'sort_order' => 4,
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        if ($existing) {
            DB::table('games')->where('id', $existing->id)->update([
                'name' => 'Dinosaur Dash',
                'slug' => 'dinosaur-dash',
                'description' => 'Run, jump over obstacles, and chase the table high score.',
                'player_mode' => 'one',
                'game_path' => '/games/dinosaur-dash',
                'sort_order' => 4,
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('games')->updateOrInsert(
            ['slug' => 'dinosaur-dash'],
            [
                'name' => 'Dinosaur Dash',
                'description' => 'Run, jump over obstacles, and chase the table high score.',
                'player_mode' => 'one',
                'game_path' => '/games/dinosaur-dash',
                'sort_order' => 4,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        $dinosaur = DB::table('games')->where('slug', 'dinosaur-dash')->first();
        $word = DB::table('games')->where('slug', 'word-scramble')->first();

        if ($dinosaur && $word && $dinosaur->id !== $word->id) {
            DB::table('games')->where('id', $dinosaur->id)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
            DB::table('games')->where('id', $word->id)->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('games')->where('slug', 'dinosaur-dash')->update([
            'name' => 'Word Scramble',
            'slug' => 'word-scramble',
            'description' => 'Unscramble eight restaurant and food words.',
            'player_mode' => 'one',
            'game_path' => '/games/word-scramble',
            'sort_order' => 4,
            'is_active' => true,
            'updated_at' => now(),
        ]);
    }
};
