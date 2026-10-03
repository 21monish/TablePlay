<?php

namespace Tests\Feature;

use App\Events\TablePlayEvent;
use App\Models\{AutomationRun, CommercialPlan, DiningTable, GameSession, Order, RestaurantSetting, Role, TableSession, User};
use App\Services\{AutomationService, EntitlementService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_expires_overdue_game_timers_and_records_the_run(): void
    {
        Event::fake([TablePlayEvent::class]);
        Carbon::setTestNow('2026-08-25 14:00:00');
        $this->enableAutomationPlan();
        RestaurantSetting::create(['restaurant_name' => 'TablePlay', 'automation_enabled' => true]);
        $table = DiningTable::create(['table_code' => 'T01', 'table_name' => 'Table 1', 'capacity' => 4, 'status' => 'occupied']);
        $visit = TableSession::create(['session_code' => 'AUT-1', 'dining_table_id' => $table->id, 'guest_count' => 2, 'opened_at' => now()->subHour(), 'status' => 'open']);
        $order = Order::create(['order_number' => 'ORD-AUT-1', 'table_session_id' => $visit->id, 'order_sequence' => 1, 'status' => 'confirmed', 'subtotal' => 100, 'tax_amount' => 0, 'discount_amount' => 0, 'total_amount' => 100]);
        $timer = GameSession::create(['table_session_id' => $visit->id, 'trigger_order_id' => $order->id, 'started_at' => now()->subHour(), 'expires_at' => now()->subMinute(), 'status' => 'active']);

        $summary = app(AutomationService::class)->runMaintenance();

        $this->assertSame(1, $summary['expired_game_sessions']);
        $this->assertDatabaseHas('game_sessions', ['id' => $timer->id, 'status' => 'expired']);
        $this->assertDatabaseHas('automation_runs', ['type' => 'maintenance', 'status' => 'success']);
        $this->assertNotNull(RestaurantSetting::first()->last_automation_at);
        Carbon::setTestNow();
    }

    public function test_admin_can_manage_automation_from_web_and_staff_api(): void
    {
        $this->enableAutomationPlan();
        RestaurantSetting::create(['restaurant_name' => 'TablePlay']);
        $admin = $this->user('admin');
        $counter = $this->user('counter');

        $this->actingAs($admin)->get('/admin/automation')
            ->assertOk()->assertSee('Automation center')->assertSee('Back up now');
        $this->actingAs($counter)->get('/admin/automation')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/automation', [
            'automation_enabled' => true,
            'auto_backup_enabled' => false,
            'backup_time' => '03:30',
            'backup_retention_days' => 21,
            'pending_order_alert_minutes' => 7,
            'service_request_alert_minutes' => 4,
        ])->assertOk()->assertJsonPath('settings.backup_time', '03:30');

        $this->assertDatabaseHas('restaurant_settings', [
            'backup_time' => '03:30',
            'backup_retention_days' => 21,
            'auto_backup_enabled' => false,
        ]);
    }

    public function test_failed_backup_is_audited_without_claiming_success(): void
    {
        RestaurantSetting::create(['restaurant_name' => 'TablePlay']);

        try {
            app(AutomationService::class)->createBackup();
            $this->fail('A development install without private database tools reported a successful backup.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', AutomationRun::where('type', 'backup')->latest('id')->value('status'));
            $this->assertNull(RestaurantSetting::first()->last_backup_at);
        }
    }

    private function user(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => ucfirst($roleName)]);
        return User::create(['role_id' => $role->id, 'name' => ucfirst($roleName), 'username' => $roleName, 'password' => 'secret-password', 'is_active' => true]);
    }

    private function enableAutomationPlan(): void
    {
        app(EntitlementService::class)->activate(CommercialPlan::where('slug', 'pro')->firstOrFail());
    }
}
