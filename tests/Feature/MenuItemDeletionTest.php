<?php

namespace Tests\Feature;

use App\Models\{Category, DiningTable, MenuItem, Order, OrderItem, Role, TableSession, User};
use App\Services\{AuditService, SecurePairingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuItemDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_menu_has_a_confirmed_delete_action(): void
    {
        $item = $this->item();
        $this->actingAs($this->user('admin'))->get(route('admin.menu'))
            ->assertOk()->assertSee(route('admin.menu-items.destroy', $item), false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee('aria-label="Delete Paneer Tikka"', false)
            ->assertSee('data-confirm-title="Delete menu item?"', false)
            ->assertSee('data-confirm-button="Delete item"', false)
            ->assertSee('data-confirm-tone="danger"', false)
            ->assertSee('data-confirm="Remove &quot;Paneer Tikka&quot; from the menu?', false)
            ->assertSee('Past orders and bills will be kept.')
            ->assertSee('data-confirmation-dialog', false)
            ->assertDontSee('onsubmit="return confirm(', false);
    }

    public function test_delete_confirmation_escapes_the_item_name(): void
    {
        $item = $this->item();
        $item->update(['name' => 'Chef\'s "Special" <script>alert(1)</script>']);
        $this->actingAs($this->user('admin'))->get(route('admin.menu'))
            ->assertOk()
            ->assertSee('data-confirm="Remove &quot;Chef&#039;s &quot;Special&quot; &lt;script&gt;alert(1)&lt;/script&gt;&quot; from the menu?', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_web_admin_removes_dish_without_destroying_order_history_or_photo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('menu-items/paneer.png', 'history-photo');
        $item = $this->item();
        $item->update(['image_path' => '/storage/menu-items/paneer.png']);
        $session = $this->createVisit();
        $order = Order::create([
            'table_session_id' => $session->id, 'order_number' => 'ORD-MENU-DELETE',
            'order_sequence' => 1, 'subtotal' => 250, 'total_amount' => 250, 'status' => 'served',
        ]);
        $line = OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id,
            'item_name_snapshot' => $item->name, 'unit_price' => 250,
            'quantity' => 1, 'line_total' => 250, 'status' => 'served',
        ]);

        $this->actingAs($this->user('admin'))->from(route('admin.menu'))
            ->delete(route('admin.menu-items.destroy', $item))
            ->assertRedirect(route('admin.menu'))->assertSessionHas('status');
        $this->assertSoftDeleted($item);
        $this->assertNull(MenuItem::find($item->id));
        $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'is_active' => false, 'is_available' => false]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total_amount' => 250]);
        $this->assertDatabaseHas('order_items', ['id' => $line->id, 'item_name_snapshot' => 'Paneer Tikka', 'unit_price' => 250]);
        $this->assertSame($item->id, $line->fresh()->menuItem->id);
        Storage::disk('public')->assertExists('menu-items/paneer.png');
        $this->assertDatabaseHas('audit_logs', ['action' => 'menu_item.deleted', 'entity_id' => $item->id]);
        $this->get(route('admin.menu'))->assertOk()->assertDontSee('Paneer Tikka');
    }

    public function test_staff_api_deletion_hides_dish_and_cannot_edit_or_delete_it_again(): void
    {
        $item = $this->item();
        Sanctum::actingAs($this->user('admin'));
        $this->deleteJson('/api/v1/admin/menu-items/'.$item->id)
            ->assertOk()->assertJsonPath('message', 'Menu item removed. Past orders and bills are preserved.');
        $this->getJson('/api/v1/admin/catalog')->assertOk()->assertJsonMissing(['name' => 'Paneer Tikka']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'menu_item.deleted.mobile', 'entity_id' => $item->id]);
        $this->deleteJson('/api/v1/admin/menu-items/'.$item->id)->assertNotFound();
        $this->postJson('/api/v1/admin/menu-items/'.$item->id.'/toggle')->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_guest_and_non_admin_roles_cannot_remove_menu_items(): void
    {
        $item = $this->item();
        $this->delete(route('admin.menu-items.destroy', $item))->assertRedirect(route('login'));
        $this->deleteJson('/api/v1/admin/menu-items/'.$item->id)->assertUnauthorized();
        foreach (['counter', 'waiter', 'kitchen'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->delete(route('admin.menu-items.destroy', $item))->assertForbidden();
            Sanctum::actingAs($user);
            $this->deleteJson('/api/v1/admin/menu-items/'.$item->id)->assertForbidden();
        }
        $this->assertNotSoftDeleted($item);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_deleted_dish_is_unavailable_to_waiters_and_cannot_be_ordered(): void
    {
        $item = $this->item();
        $session = $this->createVisit();
        Sanctum::actingAs($this->user('admin'));
        $this->deleteJson('/api/v1/admin/menu-items/'.$item->id)->assertOk();
        Sanctum::actingAs($this->user('waiter'));
        $this->getJson('/api/v1/waiter/dashboard')->assertOk()->assertJsonMissing(['name' => 'Paneer Tikka']);
        $this->postJson('/api/v1/waiter/sessions/'.$session->id.'/orders', [
            'client_request_id' => (string) Str::uuid(), 'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_tablet_does_not_see_or_order_a_deleted_dish(): void
    {
        $item = $this->item();
        $session = $this->createVisit();
        $admin = $this->user('admin');
        $qr = app(SecurePairingService::class)->issue($session->diningTable, $admin->id, 'http://tableplay.test/api/v1');
        $registration = $this->postJson('/api/v1/devices/pair-with-token', [
            'pairing_token' => $qr['pairing_token'], 'payload_signature' => $qr['payload_signature'],
            'device_uuid' => (string) Str::uuid(), 'device_name' => 'Deletion test tablet', 'app_version' => 'test',
        ])->assertCreated();
        $this->actingAs($admin)->delete(route('admin.menu-items.destroy', $item))->assertRedirect();
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => 'Bearer '.$registration['token'], 'X-Device-UUID' => $registration['device']['device_uuid']];
        $this->withHeaders($headers)->getJson('/api/v1/menu')->assertOk()->assertJsonMissing(['name' => 'Paneer Tikka']);
        $this->withHeaders($headers)->postJson('/api/v1/orders', [
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_audit_failure_rolls_back_deletion(): void
    {
        $item = $this->item();
        $this->mock(AuditService::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        });
        Sanctum::actingAs($this->user('admin'));
        $this->deleteJson('/api/v1/admin/menu-items/'.$item->id)->assertStatus(500);
        $this->assertNotSoftDeleted($item);
        $this->assertTrue($item->fresh()->is_active);
        $this->assertTrue($item->fresh()->is_available);
    }

    private function item(): MenuItem
    {
        $category = Category::create(['name' => 'Mains', 'sort_order' => 1, 'is_active' => true]);
        return MenuItem::create([
            'category_id' => $category->id, 'name' => 'Paneer Tikka', 'price' => 250,
            'food_type' => 'veg', 'spice_level' => 'none', 'is_active' => true, 'is_available' => true,
        ]);
    }

    private function user(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => Str::headline($roleName)]);
        return User::create([
            'role_id' => $role->id, 'name' => Str::headline($roleName), 'username' => $roleName.Str::random(8),
            'password' => 'secret-password', 'is_active' => true,
        ]);
    }

    private function createVisit(): TableSession
    {
        $table = DiningTable::create(['table_code' => 'T-MENU-DELETE', 'table_name' => 'Deletion test table', 'capacity' => 4, 'status' => 'occupied', 'is_active' => true]);
        return TableSession::create([
            'dining_table_id' => $table->id, 'session_code' => 'SES-MENU-DELETE',
            'guest_count' => 1, 'opened_at' => now(), 'status' => 'open',
        ]);
    }
}
