<?php

namespace Tests\Feature;

use App\Models\{Category, CommercialPlan, DiningTable, MenuItem, RestaurantSetting, Role, TableSession, User};
use App\Services\{EntitlementService, SecurePairingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestaurantPilotFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];
    private array $staff = [];
    private array $deviceHeaders;
    private DiningTable $table;
    private MenuItem $dish;
    private int $visitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        app(EntitlementService::class)->activate(CommercialPlan::where('slug', 'best')->firstOrFail());
        foreach (['admin', 'counter', 'kitchen', 'waiter'] as $name) {
            $role = Role::firstOrCreate(['name' => $name], ['display_name' => ucfirst($name)]);
            $this->staff[$name] = User::create(['role_id' => $role->id, 'name' => 'Pilot '.ucfirst($name), 'username' => 'pilot.'.$name, 'password' => 'test-only-password', 'is_active' => true]);
            $this->tokens[$name] = $this->staff[$name]->createToken('isolated pilot test')->plainTextToken;
        }
        RestaurantSetting::create(['restaurant_name' => 'Isolated Pilot', 'tax_rate' => 5, 'game_duration_minutes' => 60]);
        $this->table = DiningTable::create(['table_code' => 'PILOT-1', 'table_name' => 'Isolated pilot table', 'capacity' => 4, 'status' => 'available', 'is_active' => true]);
        $category = Category::create(['name' => 'Pilot menu', 'is_active' => true]);
        $this->dish = MenuItem::create(['category_id' => $category->id, 'name' => 'Pilot dish', 'price' => 200, 'discount_price' => 150, 'food_type' => 'veg', 'is_active' => true, 'is_available' => true]);
        $qr = app(SecurePairingService::class)->issue($this->table, $this->staff['admin']->id, 'http://pilot.test/api/v1');
        $pairing = $this->postJson('/api/v1/devices/pair-with-token', ['pairing_token' => $qr['pairing_token'], 'payload_signature' => $qr['payload_signature'], 'device_uuid' => (string) Str::uuid(), 'device_name' => 'Isolated customer tablet', 'app_version' => 'test'])->assertCreated();
        $this->deviceHeaders = ['Authorization' => 'Bearer '.$pairing['token'], 'X-Device-UUID' => $pairing['device']['device_uuid']];
        $this->as('tablet');
        $this->visitId = $this->postJson('/api/v1/table-sessions', ['guest_count' => 2])->assertCreated()['id'];
    }

    private function as(string $role): void
    {
        auth()->forgetGuards();
        $this->withHeaders($role === 'tablet' ? $this->deviceHeaders : ['Authorization' => 'Bearer '.$this->tokens[$role]]);
    }

    private function place(): int
    {
        $this->as('tablet');
        return $this->postJson('/api/v1/orders', ['items' => [['menu_item_id' => $this->dish->id, 'quantity' => 2, 'unit_price' => 1, 'special_instruction' => 'No chilli']], 'customer_note' => 'Isolated pilot order', 'total_amount' => 2])
            ->assertCreated()->assertJsonPath('subtotal', '300.00')->assertJsonPath('tax_amount', '15.00')->assertJsonPath('total_amount', '315.00')['id'];
    }

    private function confirmAndServe(int $orderId): void
    {
        $this->as('counter');
        $this->postJson('/api/v1/counter/orders/'.$orderId.'/confirm')->assertOk()->assertJsonPath('status', 'confirmed');
        $this->as('kitchen');
        foreach (['preparing', 'ready'] as $status) {
            $this->postJson('/api/v1/kitchen/orders/'.$orderId.'/'.$status)->assertOk()->assertJsonPath('status', $status)->assertJsonPath('items.0.status', $status);
        }
        $this->as('waiter');
        $this->postJson('/api/v1/waiter/orders/'.$orderId.'/served')->assertOk()->assertJsonPath('status', 'served')->assertJsonPath('items.0.status', 'served');
    }

    public function test_complete_pilot_from_customer_order_to_waiter_service_cash_and_clean_next_visit(): void
    {
        $this->assertDatabaseHas('dining_tables', ['id' => $this->table->id, 'status' => 'occupied']);
        $this->getJson('/api/v1/games/access')->assertOk()->assertJsonPath('unlocked', false);
        $orderId = $this->place();
        $water = $this->postJson('/api/v1/service-requests', ['request_type' => 'water'])->assertCreated()['id'];
        $this->as('waiter');
        $this->postJson('/api/v1/waiter/requests/'.$water.'/acknowledge')->assertOk()->assertJsonPath('assigned_to', $this->staff['waiter']->id);
        $this->postJson('/api/v1/waiter/requests/'.$water.'/complete')->assertOk()->assertJsonPath('status', 'completed');
        $this->confirmAndServe($orderId);
        $this->as('tablet');
        $this->getJson('/api/v1/table/snapshot')->assertOk()->assertJsonPath('orders.0.status', 'served')->assertJsonPath('game.unlocked', true)->assertJsonPath('game.remaining_seconds', 3600);
        $this->as('waiter');
        $this->postJson('/api/v1/waiter/sessions/'.$this->visitId.'/request-bill')->assertCreated()->assertJsonPath('request_type', 'bill');
        $this->as('counter');
        $bill = $this->postJson('/api/v1/counter/bills', ['table_session_id' => $this->visitId, 'discount_amount' => 15])->assertCreated()->assertJsonPath('grand_total', '300.00');
        $this->as('tablet');
        $this->getJson('/api/v1/table/snapshot')->assertOk()->assertJsonPath('session.status', 'billing')->assertJsonPath('session.bill.grand_total', '300.00')->assertJsonPath('game.unlocked', false);
        $this->as('counter');
        $this->postJson('/api/v1/counter/bills/'.$bill['id'].'/cash-payment', ['received_amount' => 500])->assertCreated()->assertJsonPath('amount', '300.00')->assertJsonPath('change_amount', '200.00');
        $this->assertDatabaseHas('table_sessions', ['id' => $this->visitId, 'status' => 'closed', 'closed_by' => $this->staff['counter']->id]);
        $this->assertDatabaseHas('dining_tables', ['id' => $this->table->id, 'status' => 'available']);
        $this->assertDatabaseHas('bills', ['id' => $bill['id'], 'payment_status' => 'paid']);
        $this->assertDatabaseHas('game_sessions', ['table_session_id' => $this->visitId, 'status' => 'stopped']);
        $this->assertDatabaseCount('order_status_logs', 5);
        $this->assertDatabaseHas('order_status_logs', ['order_id' => $orderId, 'to_status' => 'served', 'changed_by' => $this->staff['waiter']->id]);
        $this->as('tablet');
        $this->getJson('/api/v1/table/snapshot')->assertNotFound();
        $next = $this->postJson('/api/v1/table-sessions', ['guest_count' => 3])->assertCreated();
        $this->assertNotSame($this->visitId, $next['id']);
        $this->getJson('/api/v1/table/snapshot')->assertOk()->assertJsonPath('session.id', $next['id'])->assertJsonCount(0, 'orders')->assertJsonPath('session.bill', null)->assertJsonPath('game.unlocked', false);
        $this->getJson('/api/v1/orders/'.$orderId)->assertNotFound();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('bills', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('device_pairings', 1);
    }

    public function test_insufficient_cash_keeps_bill_unpaid_and_repeat_payment_never_double_charges(): void
    {
        $this->confirmAndServe($this->place());
        $this->as('counter');
        $bill = $this->postJson('/api/v1/counter/bills', ['table_session_id' => $this->visitId])->assertCreated();
        $this->postJson('/api/v1/counter/bills/'.$bill['id'].'/cash-payment', ['received_amount' => 314])->assertUnprocessable()->assertJsonValidationErrors('received_amount');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('bills', ['id' => $bill['id'], 'payment_status' => 'unpaid']);
        $this->assertDatabaseHas('table_sessions', ['id' => $this->visitId, 'status' => 'billing']);
        $this->postJson('/api/v1/counter/bills/'.$bill['id'].'/cash-payment', ['received_amount' => 315])->assertCreated();
        $this->postJson('/api/v1/counter/bills/'.$bill['id'].'/cash-payment', ['received_amount' => 315])->assertUnprocessable()->assertJsonValidationErrors('bill');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_repeated_bill_generation_does_not_create_or_overwrite_another_bill(): void
    {
        $this->confirmAndServe($this->place());
        $this->as('counter');
        $first = $this->postJson('/api/v1/counter/bills', ['table_session_id' => $this->visitId, 'discount_amount' => 15])->assertCreated();
        $this->postJson('/api/v1/counter/bills', ['table_session_id' => $this->visitId, 'discount_amount' => 100])->assertCreated()->assertJsonPath('id', $first['id'])->assertJsonPath('grand_total', '300.00')->assertJsonPath('discount_amount', '15.00');
        $this->assertDatabaseCount('bills', 1);
    }

    public function test_reject_requires_reason_and_never_unlocks_games_or_adds_rejected_food_to_bill(): void
    {
        $orderId = $this->place();
        $this->as('counter');
        $this->postJson('/api/v1/counter/orders/'.$orderId.'/reject', [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/counter/orders/'.$orderId.'/reject', ['reason' => 'Unavailable'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertDatabaseCount('game_sessions', 0);
        $this->as('tablet');
        $this->getJson('/api/v1/games/access')->assertOk()->assertJsonPath('unlocked', false);
        $this->as('counter');
        $this->postJson('/api/v1/counter/bills', ['table_session_id' => $this->visitId])->assertCreated()->assertJsonPath('grand_total', '0.00');
    }

    public function test_invalid_or_repeated_status_buttons_cannot_skip_steps_or_create_extra_timers(): void
    {
        $orderId = $this->place();
        $this->as('kitchen');
        $this->postJson('/api/v1/kitchen/orders/'.$orderId.'/ready')->assertUnprocessable();
        $this->as('waiter');
        $this->postJson('/api/v1/waiter/orders/'.$orderId.'/served')->assertUnprocessable();
        $this->as('counter');
        $this->postJson('/api/v1/counter/orders/'.$orderId.'/confirm')->assertOk();
        $this->postJson('/api/v1/counter/orders/'.$orderId.'/confirm')->assertUnprocessable();
        $this->assertDatabaseCount('game_sessions', 1);
        $this->as('kitchen');
        $this->postJson('/api/v1/kitchen/orders/'.$orderId.'/preparing')->assertOk();
        $this->postJson('/api/v1/kitchen/orders/'.$orderId.'/preparing')->assertUnprocessable();
        $this->postJson('/api/v1/kitchen/orders/'.$orderId.'/ready')->assertOk();
        $this->as('waiter');
        $this->postJson('/api/v1/waiter/orders/'.$orderId.'/served')->assertOk();
        $this->postJson('/api/v1/waiter/orders/'.$orderId.'/served')->assertUnprocessable();
        $this->assertDatabaseCount('order_status_logs', 5);
    }

    public function test_staff_roles_and_customer_device_cannot_use_other_roles_operational_buttons(): void
    {
        $orderId = $this->place();
        $this->as('kitchen');
        $this->postJson('/api/v1/counter/orders/'.$orderId.'/confirm')->assertForbidden();
        $this->postJson('/api/v1/counter/bills', ['table_session_id' => $this->visitId])->assertForbidden();
        $this->as('waiter');
        $this->postJson('/api/v1/counter/bills', ['table_session_id' => $this->visitId])->assertForbidden();
        $this->as('tablet');
        $this->postJson('/api/v1/counter/orders/'.$orderId.'/confirm')->assertForbidden();
        $this->postJson('/api/v1/counter/bills', ['table_session_id' => $this->visitId])->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'pending']);
        $this->assertDatabaseCount('bills', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_device_identity_is_denied_across_staff_endpoints_without_revoking_customer_access(): void
    {
        foreach ([false, true] as $verificationRequired) {
            config(['tableplay.require_privileged_email_verification' => $verificationRequired]);
            $this->as('tablet');
            foreach (['/api/v1/auth/me', '/api/v1/admin/team', '/api/v1/admin/dashboard', '/api/v1/counter/dashboard', '/api/v1/kitchen/orders', '/api/v1/waiter/dashboard'] as $path) {
                $this->getJson($path)->assertForbidden()->assertJsonPath('message', 'A staff account is required.');
            }
            $this->postJson('/api/v1/auth/logout')->assertForbidden();
            $this->postJson('/api/v1/help/chat', ['message' => 'staff-only test'])->assertForbidden();
            $this->getJson('/api/v1/menu')->assertOk()->assertJsonPath('0.menu_items.0.id', $this->dish->id);
            $this->getJson('/api/v1/table/snapshot')->assertOk()->assertJsonPath('session.id', $this->visitId);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('bills', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
