<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\RestaurantSetting;
use App\Models\Role;
use App\Models\TableSession;
use App\Models\User;
use App\Services\SecurePairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_order_uses_displayed_effective_prices_and_ignores_client_totals(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $table = DiningTable::create(['table_code' => 'T-PRICE', 'table_name' => 'Price test', 'capacity' => 4, 'status' => 'occupied', 'is_active' => true]);
        TableSession::create(['session_code' => 'SES-PRICE', 'dining_table_id' => $table->id, 'guest_count' => 2, 'opened_at' => now(), 'status' => 'open']);
        RestaurantSetting::query()->create(['restaurant_name' => 'Price test', 'tax_rate' => 5]);
        $category = Category::create(['name' => 'Mains', 'is_active' => true]);
        $dish = MenuItem::create(['category_id' => $category->id, 'name' => 'Special', 'price' => 200, 'discount_price' => 150, 'food_type' => 'veg', 'is_active' => true, 'is_available' => true]);
        $qr = app(SecurePairingService::class)->issue($table, $admin->id, 'http://tableplay.test:18740/api/v1');
        $registration = $this->postJson('/api/v1/devices/pair-with-token', [
            'pairing_token' => $qr['pairing_token'], 'payload_signature' => $qr['payload_signature'],
            'device_uuid' => (string) Str::uuid(), 'device_name' => 'Pricing test tablet', 'app_version' => 'test',
        ])->assertCreated();
        $headers = ['Authorization' => 'Bearer '.$registration['token'], 'X-Device-UUID' => $registration['device']['device_uuid']];
        $this->withHeaders($headers)->getJson('/api/v1/menu')->assertOk()->assertJsonPath('0.menu_items.0.effective_price', '150.00');
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', [
            'items' => [['menu_item_id' => $dish->id, 'quantity' => 2, 'unit_price' => 1]], 'total_amount' => 2,
        ])->assertCreated();
        $this->assertEquals(300, $response['subtotal']);
        $this->assertEquals(15, $response['tax_amount']);
        $this->assertEquals(315, $response['total_amount']);
        $this->assertEquals(150, $response['items'][0]['unit_price']);
        $this->assertEquals(300, $response['items'][0]['line_total']);
        $dish->update(['discount_price' => null]);
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', ['items' => [['menu_item_id' => $dish->id, 'quantity' => 1]]])->assertCreated();
        $this->assertEquals(200, $response['items'][0]['unit_price']);
        $this->assertEquals(210, $response['total_amount']);
    }
}
