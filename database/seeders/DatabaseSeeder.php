<?php

namespace Database\Seeders;

use App\Models\DiningTable;
use App\Models\Game;
use App\Models\RestaurantSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $roles = collect([
            'admin' => 'Administrator',
            'counter' => 'Counter',
            'kitchen' => 'Kitchen',
            'waiter' => 'Waiter',
            'superadmin' => 'Platform Super Admin',
        ])->map(fn ($display, $name) => Role::firstOrCreate(
            ['name' => $name],
            ['display_name' => $display],
        ));

        foreach (['admin', 'counter', 'kitchen', 'waiter'] as $name) {
            User::updateOrCreate(['username' => $name], [
                'role_id' => $roles[$name]->id,
                'name' => ucfirst($name),
                'email' => $name.'@tableplay.local',
                'password' => Hash::make('TablePlay@123'),
                'pin' => in_array($name, ['kitchen', 'waiter'], true)
                    ? Hash::make($name === 'kitchen' ? '2468' : '1357')
                    : null,
                'is_active' => true,
            ]);
        }

        if ($password = env('TABLEPLAY_SUPERADMIN_PASSWORD')) {
            User::updateOrCreate(['username' => 'superadmin'], [
                'role_id' => $roles['superadmin']->id, 'name' => 'TablePlay Super Admin',
                'email' => 'superadmin@tableplay.local', 'password' => Hash::make($password),
                'pin' => null, 'is_active' => true,
            ]);
        }

        RestaurantSetting::firstOrCreate(['id' => 1], [
            'restaurant_name' => 'TablePlay Restaurant',
            'currency' => 'INR',
            'tax_name' => 'GST',
            'tax_rate' => 5,
            'game_duration_minutes' => 60,
        ]);

        foreach (range(1, 10) as $number) {
            DiningTable::firstOrCreate(['table_code' => sprintf('T%02d', $number)], [
                'table_name' => 'Table '.$number,
                'capacity' => 4,
            ]);
        }

        $this->call(MenuCatalogSeeder::class);

        $games = [
            ['TablePlay Snake', 'snake', 'Guide the snake, collect bites, and set the table high score.', 'one', 1],
            ['Memory Match', 'memory-match', 'Match every pair using the fewest moves.', 'one', 2],
            ['Quick Math', 'quick-math', 'Solve rapid-fire sums before the clock runs out.', 'one', 3],
            ['Dinosaur Dash', 'dinosaur-dash', 'Run, jump over obstacles, and chase the table high score.', 'one', 4],
            ['Balloon Ascent', 'balloon-ascent', 'Steer through spike gates, collect bubbles, and use sky power-ups.', 'one', 5],
            ['Tic Tac Toe', 'tic-tac-toe', 'Classic pass-and-play noughts and crosses.', 'two', 6],
            ['Connect Four', 'connect-four', 'Two-player strategy: connect four tokens in any direction.', 'two', 7],
            ['Reaction Duel', 'reaction-duel', 'Two players race to tap only after the GO signal.', 'two', 8],
            ['Air Hockey', 'air-hockey', 'Two-player tabletop hockey. First to five goals wins.', 'two', 9],
            ['Table Race', 'table-race', 'Four-player dice race around the table. First to 30 wins.', 'four', 10],
            ['Quiz Battle', 'quiz-battle', 'Four players take turns answering quick quiz questions.', 'four', 11],
            ['Tap Elimination', 'tap-elimination', 'Four-player reaction rounds with a changing active target.', 'four', 12],
            ['Ludo Mini', 'ludo-mini', 'A compact four-player race to bring one token home.', 'four', 13],
        ];

        foreach ($games as [$name, $slug, $description, $playerMode, $sortOrder]) {
            Game::firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'description' => $description,
                'player_mode' => $playerMode,
                'game_path' => '/games/'.$slug,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);
        }
    }
}
