<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

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
            'restaurant_owner' => 'Restaurant Owner',
        ])->map(fn ($display, $name) => Role::firstOrCreate(
            ['name' => $name],
            ['display_name' => $display],
        ));

        $this->provisionSuperAdmin($roles['superadmin']);

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

    private function provisionSuperAdmin(Role $role): void
    {
        $email = strtolower(trim((string) config('tableplay.superadmin.email', '')));
        $password = (string) config('tableplay.superadmin.password', '');

        if ($email === '' && $password === '') {
            return;
        }

        $credentials = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email:rfc', 'max:255'],
                'password' => ['required', 'string', 'max:255', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
            ],
        )->validate();

        $user = User::query()->firstOrNew(['username' => 'superadmin']);
        $emailChanged = $user->exists && $user->email !== $credentials['email'];

        $user->forceFill([
            'role_id' => $role->id,
            'name' => trim((string) config('tableplay.superadmin.name', 'TablePlay Super Admin')),
            'email' => $credentials['email'],
            'password' => Hash::make($credentials['password']),
            'pin' => null,
            'is_active' => true,
            'email_verified_at' => config('tableplay.require_privileged_email_verification')
                ? ($emailChanged ? null : $user->email_verified_at)
                : ($user->email_verified_at ?? now()),
        ])->save();
    }
}
