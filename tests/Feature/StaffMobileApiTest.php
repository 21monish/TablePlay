<?php

namespace Tests\Feature;

use App\Models\{CommercialPlan, Device, DiningTable, Order, RestaurantSetting, Role, ServiceRequest, TableSession, User};
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffMobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_role_and_never_serializes_password_or_pin_hashes(): void
    {
        $user = $this->user('waiter');
        $user->update(['pin' => '2468']);

        $this->postJson('/api/v1/auth/login', ['username' => 'waiter', 'pin' => '2468', 'device_name' => 'Waiter phone'])
            ->assertOk()
            ->assertJsonPath('user.role.name', 'waiter')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.pin')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'username', 'role']]);
    }

    public function test_login_rejects_ambiguous_or_malformed_credentials(): void
    {
        $this->user('waiter');

        $this->postJson('/api/v1/auth/login', [
            'username' => 'waiter',
            'password' => 'secret-password',
            'pin' => '2468',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password', 'pin']);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'waiter',
            'pin' => '12ab',
        ])->assertUnprocessable()->assertJsonValidationErrors('pin');
    }

    public function test_restaurant_admin_cannot_create_or_list_platform_superadmins(): void
    {
        $admin = $this->user('admin');
        $superAdminRole = Role::firstOrCreate(
            ['name' => 'superadmin'],
            ['display_name' => 'Platform Super Admin'],
        );
        User::create([
            'role_id' => $superAdminRole->id,
            'name' => 'Platform Owner',
            'username' => 'platform-owner',
            'password' => 'secret-password',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/staff', [
            'role_id' => $superAdminRole->id,
            'name' => 'Unauthorized Owner',
            'username' => 'unauthorized-owner',
            'password' => 'secret-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');

        $this->getJson('/api/v1/admin/team')
            ->assertOk()
            ->assertJsonMissing(['name' => 'superadmin'])
            ->assertJsonMissing(['username' => 'platform-owner']);

        $this->postJson('/api/v1/admin/staff/'.User::where('username', 'platform-owner')->value('id').'/toggle')
            ->assertForbidden();
    }

    public function test_admin_mobile_overview_is_role_protected(): void
    {
        $admin = $this->user('admin');
        $counter = $this->user('counter');
        $this->table();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/overview')->assertOk()->assertJsonStructure([
            'stats' => ['sales_today', 'orders_today', 'open_tables', 'pending_orders'],
            'tables', 'staff', 'menu_items', 'games', 'devices', 'settings', 'health',
        ]);

        Sanctum::actingAs($counter);
        $this->getJson('/api/v1/admin/overview')->assertForbidden();
    }

    public function test_admin_app_matches_the_website_management_workflows(): void
    {
        $admin = $this->user('admin');
        $waiterRole = Role::firstOrCreate(['name' => 'waiter'], ['display_name' => 'Waiter']);
        RestaurantSetting::create(['restaurant_name' => 'TablePlay', 'currency' => 'INR', 'tax_name' => 'GST', 'tax_rate' => 5, 'game_duration_minutes' => 60]);
        app(EntitlementService::class)->activate(CommercialPlan::where('slug', 'premium')->firstOrFail());
        Sanctum::actingAs($admin);

        foreach (['dashboard', 'tables', 'team', 'catalog', 'reports', 'settings', 'system'] as $section) {
            $this->getJson('/api/v1/admin/'.$section)->assertOk();
        }

        $table = $this->postJson('/api/v1/admin/tables', ['table_code' => 'T20', 'table_name' => 'Patio 20', 'capacity' => 6])
            ->assertCreated()->assertJsonPath('status', 'available');
        $device = Device::create(['device_uuid' => (string) Str::uuid(), 'device_name' => 'Patio tablet', 'device_type' => 'tablet', 'is_active' => true]);
        $pairing = $this->postJson('/api/v1/admin/pairings', ['device_id' => $device->id, 'dining_table_id' => $table['id']])
            ->assertCreated()->assertJsonPath('dining_table.id', $table['id']);
        $this->postJson('/api/v1/admin/pairings/'.$pairing['id'].'/unpair')->assertOk()->assertJsonPath('is_active', false);

        $this->postJson('/api/v1/admin/staff', [
            'role_id' => $waiterRole->id, 'name' => 'Floor Captain', 'username' => 'captain',
            'email' => 'captain@tableplay.local', 'password' => 'strong-password', 'pin' => '4321',
        ])->assertCreated()->assertJsonPath('role.name', 'waiter')->assertJsonMissingPath('password')->assertJsonMissingPath('pin');

        $category = $this->postJson('/api/v1/admin/categories', ['name' => 'Desserts', 'description' => 'Sweet dishes', 'sort_order' => 4])
            ->assertCreated();
        $this->postJson('/api/v1/admin/menu-items', [
            'category_id' => $category['id'], 'name' => 'Kulfi', 'price' => 120,
            'food_type' => 'veg', 'preparation_minutes' => 5,
        ])->assertCreated()->assertJsonPath('category.name', 'Desserts');
        $this->postJson('/api/v1/admin/games', [
            'name' => 'Memory Match', 'slug' => 'memory-match', 'description' => 'Match the cards.',
            'player_mode' => 'one', 'game_path' => '/games/memory-match', 'sort_order' => 8,
        ])->assertCreated()->assertJsonPath('is_active', true);

        $this->putJson('/api/v1/admin/settings', [
            'restaurant_name' => 'TablePlay Pilot', 'tagline' => 'Order. Play. Enjoy.', 'address' => 'Main Road',
            'phone' => '1234567890', 'email' => 'hello@tableplay.local', 'gstin' => 'GST-01', 'brand_color' => '#ef6a3a',
            'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'tax_name' => 'GST', 'tax_rate' => 7,
            'game_duration_minutes' => 45, 'kitchen_refresh_seconds' => 5, 'device_offline_minutes' => 6,
            'receipt_footer' => 'Thank you',
        ])->assertOk()->assertJsonPath('restaurant_name', 'TablePlay Pilot')->assertJsonPath('game_duration_minutes', 45);

        $this->deleteJson('/api/v1/admin/tables/'.$table['id'])->assertOk()->assertJsonPath('mode', 'deleted');
        $this->assertDatabaseMissing('dining_tables', ['id' => $table['id']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'table.deleted.mobile']);
    }

    public function test_waiter_can_acknowledge_requests_serve_ready_orders_and_request_bill(): void
    {
        $waiter = $this->user('waiter');
        $table = $this->table();
        $session = TableSession::create(['session_code' => 'SES-WAITER-1', 'dining_table_id' => $table->id, 'guest_count' => 3, 'opened_at' => now(), 'status' => 'open']);
        $serviceRequest = ServiceRequest::create(['table_session_id' => $session->id, 'request_type' => 'water', 'status' => 'pending']);
        $order = Order::create(['order_number' => 'ORD-WAITER-1', 'table_session_id' => $session->id, 'order_sequence' => 1, 'status' => 'ready', 'subtotal' => 100, 'tax_amount' => 5, 'discount_amount' => 0, 'total_amount' => 105, 'confirmed_at' => now()]);

        Sanctum::actingAs($waiter);
        $this->getJson('/api/v1/waiter/dashboard')->assertOk()->assertJsonCount(1, 'requests')->assertJsonCount(1, 'ready_orders');
        $this->postJson("/api/v1/waiter/requests/{$serviceRequest->id}/acknowledge")->assertOk()->assertJsonPath('status', 'acknowledged')->assertJsonPath('assigned_to', $waiter->id);
        $this->postJson("/api/v1/waiter/requests/{$serviceRequest->id}/complete")->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson("/api/v1/waiter/orders/{$order->id}/served")->assertOk()->assertJsonPath('status', 'served');
        $this->postJson("/api/v1/waiter/sessions/{$session->id}/request-bill")->assertCreated()->assertJsonPath('request_type', 'bill');
    }

    public function test_staff_app_help_is_role_aware_and_available_offline(): void
    {
        Sanctum::actingAs($this->user('waiter'));

        $this->postJson('/api/v1/help/chat', ['message' => 'How do I handle a guest request?'])
            ->assertOk()
            ->assertJsonPath('title', 'Waiter floor workflow')
            ->assertJsonPath('offline', true)
            ->assertJsonStructure(['reply', 'steps', 'suggestions']);
    }

    public function test_waiter_can_open_a_table_session_but_counter_cannot_use_waiter_routes(): void
    {
        $waiter = $this->user('waiter');
        $counter = $this->user('counter');
        $table = $this->table();

        Sanctum::actingAs($waiter);
        $this->postJson("/api/v1/waiter/tables/{$table->id}/sessions", ['guest_count' => 4])
            ->assertOk()->assertJsonPath('guest_count', 4)->assertJsonPath('status', 'open');

        Sanctum::actingAs($counter);
        $this->getJson('/api/v1/waiter/dashboard')->assertForbidden();
    }

    private function table(): DiningTable
    {
        return DiningTable::create(['table_code' => 'T01', 'table_name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'is_active' => true]);
    }

    private function user(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => ucfirst($roleName)]);
        return User::create(['role_id' => $role->id, 'name' => ucfirst($roleName), 'username' => $roleName, 'password' => 'secret-password', 'is_active' => true]);
    }
}
