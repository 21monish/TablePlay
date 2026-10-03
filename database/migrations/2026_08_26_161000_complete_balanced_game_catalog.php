<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $games = [
            ['Word Scramble', 'word-scramble', 'Unscramble eight restaurant and food words.', 'one', 4],
            ['Reaction Duel', 'reaction-duel', 'Two players race to tap only after the GO signal.', 'two', 7],
            ['Air Hockey', 'air-hockey', 'Two-player tabletop hockey. First to five goals wins.', 'two', 8],
            ['Quiz Battle', 'quiz-battle', 'Four players take turns answering quick quiz questions.', 'four', 10],
            ['Tap Elimination', 'tap-elimination', 'Four-player reaction rounds with a changing active target.', 'four', 11],
            ['Ludo Mini', 'ludo-mini', 'A compact four-player race to bring one token home.', 'four', 12],
        ];

        foreach ($games as [$name, $slug, $description, $mode, $sort]) {
            DB::table('games')->updateOrInsert(['slug' => $slug], [
                'name' => $name,
                'description' => $description,
                'player_mode' => $mode,
                'game_path' => '/games/'.$slug,
                'sort_order' => $sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('games')->where('slug', 'tap-challenge')->update([
            'is_active' => false,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('games')->whereIn('slug', [
            'word-scramble', 'reaction-duel', 'air-hockey',
            'quiz-battle', 'tap-elimination', 'ludo-mini',
        ])->delete();
        DB::table('games')->where('slug', 'tap-challenge')->update(['is_active' => true]);
    }
};
