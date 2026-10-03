<?php

namespace Tests\Feature;

use App\Models\{CommercialPlan, Role, User};
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffWebAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_and_manage_a_table_while_counter_is_forbidden(): void
    {
        [$admin, $counter] = $this->staffUsers();

        $this->post('/login', ['username' => 'admin', 'credential' => 'secret-password'])
            ->assertRedirect('/admin')
            ->assertCookie(auth()->guard()->getRecallerName());
        $this->get('/admin')->assertOk();
        $this->post('/admin/tables', ['table_code' => 'T01', 'table_name' => 'Table 1', 'capacity' => 4])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertDatabaseHas('dining_tables', ['table_code' => 'T01']);

        auth()->logout();
        auth()->forgetGuards();
        $this->actingAs($counter)->get('/admin')->assertForbidden();
        $this->actingAs($counter)->get('/counter')->assertOk();
    }

    public function test_admin_can_open_every_management_and_diagnostics_screen(): void
    {
        [$admin] = $this->staffUsers();
        app(EntitlementService::class)->activate(CommercialPlan::where('slug', 'premium')->firstOrFail());

        foreach (['/admin', '/admin/tables', '/admin/menu', '/admin/team', '/admin/games', '/admin/reports', '/admin/settings', '/admin/system'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }

        $this->actingAs($admin)->getJson('/admin/system/health')
            ->assertOk()
            ->assertJsonStructure([
                'overall', 'checked_at',
                'checks' => ['database', 'cache', 'storage', 'queue', 'reverb'],
                'runtime',
                'devices' => ['registered', 'active', 'online', 'threshold_minutes'],
            ]);
    }

    public function test_admin_can_update_brand_and_operational_settings(): void
    {
        [$admin] = $this->staffUsers();

        $this->actingAs($admin)->put('/admin/settings', [
            'restaurant_name' => 'TablePlay Kitchen',
            'tagline' => 'Dine. Play. Delight.',
            'address' => 'Pilot restaurant',
            'phone' => '9999999999',
            'email' => 'hello@tableplay.local',
            'gstin' => 'TESTGSTIN',
            'brand_color' => '#ef6a3a',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'tax_name' => 'GST',
            'tax_rate' => 5,
            'game_duration_minutes' => 60,
            'kitchen_refresh_seconds' => 4,
            'device_offline_minutes' => 5,
            'receipt_footer' => 'Thank you for dining with us.',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('restaurant_settings', [
            'restaurant_name' => 'TablePlay Kitchen',
            'brand_color' => '#ef6a3a',
            'timezone' => 'Asia/Kolkata',
            'kitchen_refresh_seconds' => 4,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated', 'user_id' => $admin->id]);
    }

    private function staffUsers(): array
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrator']);
        $counterRole = Role::firstOrCreate(['name' => 'counter'], ['display_name' => 'Counter']);

        return [
            User::create(['role_id' => $adminRole->id, 'name' => 'Admin', 'username' => 'admin', 'password' => 'secret-password', 'is_active' => true]),
            User::create(['role_id' => $counterRole->id, 'name' => 'Counter', 'username' => 'counter', 'password' => 'secret-password', 'is_active' => true]),
        ];
    }
}
