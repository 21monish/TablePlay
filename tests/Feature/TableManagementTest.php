<?php

namespace Tests\Feature;

use App\Models\{Device, DevicePairing, DiningTable, Role, TableSession, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_screen_exposes_table_management_controls(): void
    {
        $admin = $this->user('admin');
        $table = $this->table();

        $this->actingAs($admin)->get('/admin/tables')
            ->assertOk()
            ->assertSee('Manage')
            ->assertSee('Delete unused table')
            ->assertSee(route('admin.tables.destroy', $table), false);
    }

    public function test_unused_table_is_permanently_deleted_and_its_pairing_is_removed(): void
    {
        $admin = $this->user('admin');
        $table = $this->table();
        $device = Device::create([
            'device_uuid' => '00000000-0000-4000-8000-000000000001',
            'device_name' => 'Pilot tablet',
            'device_type' => 'tablet',
            'is_active' => true,
        ]);
        $pairing = DevicePairing::create([
            'device_id' => $device->id,
            'dining_table_id' => $table->id,
            'paired_by' => $admin->id,
            'paired_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)->delete(route('admin.tables.destroy', $table))
            ->assertRedirect()
            ->assertSessionHas('status', 'Unused table permanently deleted.');

        $this->assertDatabaseMissing('dining_tables', ['id' => $table->id]);
        $this->assertDatabaseMissing('device_pairings', ['id' => $pairing->id]);
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'table.deleted', 'entity_id' => $table->id]);
    }

    public function test_table_with_service_history_is_archived_instead_of_deleted(): void
    {
        $admin = $this->user('admin');
        $table = $this->table();
        $session = TableSession::create([
            'session_code' => 'VISIT-HISTORY-1',
            'dining_table_id' => $table->id,
            'guest_count' => 2,
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
            'status' => 'closed',
        ]);

        $this->actingAs($admin)->delete(route('admin.tables.destroy', $table))
            ->assertRedirect()
            ->assertSessionHas('status', 'Table has service history, so it was archived safely.');

        $this->assertDatabaseHas('dining_tables', ['id' => $table->id, 'status' => 'disabled', 'is_active' => false]);
        $this->assertDatabaseHas('table_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'table.archived', 'entity_id' => $table->id]);
    }

    public function test_table_with_an_active_visit_cannot_be_removed_or_disabled(): void
    {
        $admin = $this->user('admin');
        $table = $this->table();
        TableSession::create([
            'session_code' => 'VISIT-ACTIVE-1',
            'dining_table_id' => $table->id,
            'guest_count' => 4,
            'opened_at' => now(),
            'status' => 'open',
        ]);

        $this->actingAs($admin)->from('/admin/tables')->delete(route('admin.tables.destroy', $table))
            ->assertRedirect('/admin/tables')
            ->assertSessionHasErrors('table');
        $this->actingAs($admin)->from('/admin/tables')->put(route('admin.tables.update', $table), [
            'table_name' => 'Blocked removal',
            'capacity' => 4,
            'status' => 'disabled',
        ])->assertRedirect('/admin/tables')->assertSessionHasErrors('table');

        $this->assertDatabaseHas('dining_tables', ['id' => $table->id, 'status' => 'available', 'is_active' => true]);
    }

    public function test_disabling_an_idle_table_also_unpairs_its_tablet(): void
    {
        $admin = $this->user('admin');
        $table = $this->table();
        $device = Device::create([
            'device_uuid' => '00000000-0000-4000-8000-000000000002',
            'device_name' => 'Table tablet',
            'device_type' => 'tablet',
            'is_active' => true,
        ]);
        $pairing = DevicePairing::create([
            'device_id' => $device->id,
            'dining_table_id' => $table->id,
            'paired_by' => $admin->id,
            'paired_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.tables.update', $table), [
            'table_name' => 'Table disabled',
            'capacity' => 6,
            'status' => 'disabled',
        ])->assertRedirect()->assertSessionHas('status', 'Table disabled and removed from service.');

        $this->assertDatabaseHas('dining_tables', ['id' => $table->id, 'table_name' => 'Table disabled', 'capacity' => 6, 'status' => 'disabled', 'is_active' => false]);
        $this->assertDatabaseHas('device_pairings', ['id' => $pairing->id, 'is_active' => false]);
    }

    public function test_counter_cannot_delete_tables(): void
    {
        $counter = $this->user('counter');
        $table = $this->table();

        $this->actingAs($counter)->delete(route('admin.tables.destroy', $table))->assertForbidden();
        $this->assertDatabaseHas('dining_tables', ['id' => $table->id]);
    }

    private function table(): DiningTable
    {
        return DiningTable::create([
            'table_code' => 'T99',
            'table_name' => 'Test Table',
            'capacity' => 4,
            'status' => 'available',
            'is_active' => true,
        ]);
    }

    private function user(string $roleName): User
    {
        $role = Role::create(['name' => $roleName, 'display_name' => ucfirst($roleName)]);

        return User::create([
            'role_id' => $role->id,
            'name' => ucfirst($roleName).' User',
            'username' => $roleName,
            'password' => 'secret-password',
            'is_active' => true,
        ]);
    }
}
