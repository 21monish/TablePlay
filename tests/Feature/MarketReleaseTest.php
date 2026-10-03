<?php

namespace Tests\Feature;

use App\Models\CommercialPlan;
use App\Models\DiningTable;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Models\TableSession;
use App\Models\User;
use App\Services\GameAccessService;
use App\Services\EntitlementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_production_seed_contains_no_restaurant_demo_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('menu_items', 0);
        $this->assertDatabaseCount('dining_tables', 0);
        $this->assertSame(0, RestaurantSetting::count());
        $this->assertSame(0, User::count());
        $this->assertSame(13, Game::where('is_active', true)->count());
        $this->assertSame(5, Game::where('is_active', true)->where('player_mode', 'one')->count());
        $this->assertSame(4, Game::where('is_active', true)->where('player_mode', 'two')->count());
        $this->assertSame(4, Game::where('is_active', true)->where('player_mode', 'four')->count());
        $this->assertDatabaseHas('games', ['slug' => 'memory-match', 'is_active' => true]);
        $this->assertDatabaseHas('games', ['slug' => 'quick-math', 'is_active' => true]);
        $this->assertDatabaseHas('games', ['slug' => 'connect-four', 'player_mode' => 'two', 'is_active' => true]);
        $this->assertDatabaseHas('games', ['slug' => 'table-race', 'player_mode' => 'four', 'is_active' => true]);
        $this->assertDatabaseHas('games', ['slug' => 'dinosaur-dash', 'player_mode' => 'one', 'is_active' => true]);
        $this->assertDatabaseHas('games', ['slug' => 'balloon-ascent', 'player_mode' => 'one', 'is_active' => true]);
        $this->assertDatabaseHas('games', ['slug' => 'air-hockey', 'player_mode' => 'two', 'is_active' => true]);
        $this->assertDatabaseHas('games', ['slug' => 'ludo-mini', 'player_mode' => 'four', 'is_active' => true]);
    }

    public function test_superadmin_is_provisioned_only_from_valid_deployment_secrets(): void
    {
        config([
            'tableplay.require_privileged_email_verification' => true,
            'tableplay.superadmin.name' => 'Platform Owner',
            'tableplay.superadmin.email' => 'owner@example.com',
            'tableplay.superadmin.password' => 'Strong@123',
        ]);

        $this->seed(DatabaseSeeder::class);

        $user = User::where('username', 'superadmin')->firstOrFail();
        $this->assertSame('owner@example.com', $user->email);
        $this->assertSame('superadmin', $user->role->name);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Strong@123', $user->password));
        $this->assertDatabaseCount('dining_tables', 0);
        $this->assertDatabaseCount('menu_items', 0);
    }

    public function test_game_timer_can_be_extended_and_stopped_only_while_active(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        app(EntitlementService::class)->activate(CommercialPlan::where('slug', 'best')->firstOrFail());
        $table = DiningTable::create([
            'table_code' => 'T01',
            'table_name' => 'Table 1',
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        $tableSession = TableSession::create([
            'session_code' => 'SES-MARKET-1',
            'dining_table_id' => $table->id,
            'guest_count' => 2,
            'opened_at' => now(),
            'status' => 'open',
        ]);
        $order = Order::create([
            'order_number' => 'ORD-MARKET-1',
            'table_session_id' => $tableSession->id,
            'order_sequence' => 1,
            'status' => 'confirmed',
            'subtotal' => 100,
            'tax_amount' => 5,
            'discount_amount' => 0,
            'total_amount' => 105,
        ]);
        $gameSession = GameSession::create([
            'table_session_id' => $tableSession->id,
            'trigger_order_id' => $order->id,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(60),
            'status' => 'active',
        ]);

        $service = app(GameAccessService::class);
        $extended = $service->extend($gameSession, 30);
        $this->assertSame('2026-08-25 11:30:00', $extended->expires_at->format('Y-m-d H:i:s'));
        $this->assertTrue($service->state($tableSession)['unlocked']);

        $stopped = $service->stop($extended);
        $this->assertSame('stopped', $stopped->status);
        $this->assertFalse($service->state($tableSession)['unlocked']);

        $this->expectException(ValidationException::class);
        $service->extend($stopped, 15);
    }

    public function test_expired_game_timer_cannot_be_resurrected(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');
        app(EntitlementService::class)->activate(CommercialPlan::where('slug', 'best')->firstOrFail());
        $table = DiningTable::create([
            'table_code' => 'T02',
            'table_name' => 'Table 2',
            'capacity' => 4,
            'status' => 'occupied',
        ]);
        $tableSession = TableSession::create([
            'session_code' => 'SES-MARKET-2',
            'dining_table_id' => $table->id,
            'guest_count' => 2,
            'opened_at' => now()->subHours(2),
            'status' => 'open',
        ]);
        $order = Order::create([
            'order_number' => 'ORD-MARKET-2',
            'table_session_id' => $tableSession->id,
            'order_sequence' => 1,
            'status' => 'confirmed',
            'subtotal' => 100,
            'tax_amount' => 5,
            'discount_amount' => 0,
            'total_amount' => 105,
        ]);
        $gameSession = GameSession::create([
            'table_session_id' => $tableSession->id,
            'trigger_order_id' => $order->id,
            'started_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
            'status' => 'active',
        ]);

        try {
            app(GameAccessService::class)->extend($gameSession, 15);
            $this->fail('The expired timer was extended.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('game_sessions', [
                'id' => $gameSession->id,
                'status' => 'expired',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
