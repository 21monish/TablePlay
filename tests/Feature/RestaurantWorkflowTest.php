<?php

namespace Tests\Feature;

use App\Models\{Category, DiningTable, MenuItem, RestaurantSetting, Role, User};
use App\Services\SecurePairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestaurantWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_order_game_and_cash_payment_workflow(): void
    {
        $this->freezeTime();
        $counterRole = Role::create(['name' => 'counter', 'display_name' => 'Counter']);
        $kitchenRole = Role::create(['name' => 'kitchen', 'display_name' => 'Kitchen']);
        $counter = User::create(['role_id' => $counterRole->id, 'name' => 'Counter', 'username' => 'counter', 'password' => Hash::make('secret'), 'is_active' => true]);
        $kitchen = User::create(['role_id' => $kitchenRole->id, 'name' => 'Kitchen', 'username' => 'kitchen', 'password' => Hash::make('secret'), 'is_active' => true]);
        $table = DiningTable::create(['table_code' => 'T01', 'table_name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'is_active' => true]);
        RestaurantSetting::create(['restaurant_name' => 'TablePlay', 'tax_rate' => 5, 'game_duration_minutes' => 60]);
        $category = Category::create(['name' => 'Mains']);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'Biryani', 'price' => 200, 'food_type' => 'veg']);

        $pairingQr = app(SecurePairingService::class)->issue($table, $counter->id, 'http://tableplay.test/api/v1');
        $registration = $this->postJson('/api/v1/devices/pair-with-token', [
            'pairing_token' => $pairingQr['pairing_token'],
            'payload_signature' => $pairingQr['payload_signature'],
            'device_uuid' => (string) Str::uuid(),
            'device_name' => 'Tablet 1',
            'app_version' => '1.9.1',
        ])->assertCreated();
        $deviceHeaders = ['Authorization' => 'Bearer '.$registration['token'], 'X-Device-UUID' => $registration['device']['device_uuid']];
        $session = $this->withHeaders($deviceHeaders)->postJson('/api/v1/table-sessions', ['guest_count' => 2])->assertCreated();
        $order = $this->withHeaders($deviceHeaders)->postJson('/api/v1/orders', ['items' => [['menu_item_id' => $item->id, 'quantity' => 2]]])->assertCreated()->assertJsonPath('total_amount', '420.00');

        $counterToken = $counter->createToken('test')->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($counterToken)->postJson('/api/v1/counter/orders/'.$order['id'].'/confirm')->assertOk()->assertJsonPath('status', 'confirmed');
        auth()->forgetGuards();
        $this->withHeaders($deviceHeaders)->getJson('/api/v1/games/access')->assertOk()->assertJsonPath('unlocked', true)->assertJsonPath('remaining_seconds', 3600);
        $this->withHeaders($deviceHeaders)->getJson('/api/v1/table/snapshot')
            ->assertOk()
            ->assertJsonPath('session.id', $session['id'])
            ->assertJsonPath('orders.0.id', $order['id'])
            ->assertJsonPath('game.unlocked', true)
            ->assertJsonPath('game.remaining_seconds', 3600);

        $kitchenToken = $kitchen->createToken('test')->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($kitchenToken)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('role.name', 'kitchen');
        foreach (['preparing', 'ready', 'served'] as $status) $this->withToken($kitchenToken)->postJson('/api/v1/kitchen/orders/'.$order['id'].'/'.$status)->assertOk()->assertJsonPath('status', $status);

        auth()->forgetGuards();
        $bill = $this->withToken($counterToken)->postJson('/api/v1/counter/bills', ['table_session_id' => $session['id']])->assertCreated()->assertJsonPath('grand_total', '420.00');
        $this->withToken($counterToken)->postJson('/api/v1/counter/bills/'.$bill['id'].'/cash-payment', ['received_amount' => 500])->assertCreated()->assertJsonPath('change_amount', '80.00');
        $this->assertDatabaseHas('table_sessions', ['id' => $session['id'], 'status' => 'closed']);
        $this->assertDatabaseHas('game_sessions', ['table_session_id' => $session['id'], 'status' => 'stopped']);
    }
}
