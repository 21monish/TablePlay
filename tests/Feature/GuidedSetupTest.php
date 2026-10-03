<?php

namespace Tests\Feature;

use App\Models\{CommercialPlan, DiningTable, PairingToken, RestaurantSetting, Role, User};
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuidedSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_resume_setup_and_create_missing_tables(): void
    {
        [$admin, $counter] = $this->users();
        RestaurantSetting::create(['restaurant_name' => 'TablePlay Restaurant']);

        $this->actingAs($admin)->get('/admin/setup')->assertOk()->assertSee('Restaurant setup')->assertSee('Create dining tables');
        $this->actingAs($counter)->get('/admin/setup')->assertForbidden();
        $this->actingAs($admin)->post('/admin/setup/tables', ['count' => 4, 'capacity' => 6])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(4, DiningTable::count());
        $this->assertDatabaseHas('dining_tables', ['table_code' => 'T04', 'capacity' => 6]);
        $this->assertNotNull(RestaurantSetting::first()->setup_started_at);
    }

    public function test_single_use_qr_registers_and_pairs_customer_tablet_atomically(): void
    {
        [$admin] = $this->users();
        $this->activateTrial();
        config()->set('app.url', 'http://192.168.50.10:8000');
        RestaurantSetting::create(['restaurant_name' => 'QR Restaurant']);
        $table = DiningTable::create(['table_code' => 'T01', 'table_name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'is_active' => true]);

        $response = $this->actingAs($admin)->post(route('admin.setup.pairing', $table));
        $response->assertRedirect(route('admin.setup.index'));
        $payload = session('pairing_payload');
        $this->assertSame('tableplay_pairing', $payload['type']);
        $this->assertSame(2, $payload['version']);
        $this->assertStringEndsWith('/api/v1', $payload['api_base_url']);
        $this->assertStringNotContainsString('127.0.0.1', $payload['api_base_url']);
        $this->assertStringNotContainsString('localhost', $payload['api_base_url']);
        $this->assertSame(64, strlen($payload['pairing_token']));

        $device = ['pairing_token' => $payload['pairing_token'], 'payload_signature' => $payload['payload_signature'], 'device_uuid' => 'dff6b0e8-2d84-4f45-b32c-cdd768ab1c01', 'device_name' => 'Table 1 Tablet', 'app_version' => '1.5.0'];
        $this->postJson('/api/v1/devices/pair-with-token', $device)->assertCreated()
            ->assertJsonPath('table_code', 'T01')->assertJsonStructure(['token', 'device', 'pairing']);
        $this->assertDatabaseHas('device_pairings', ['dining_table_id' => $table->id, 'is_active' => true]);
        $this->assertNotNull(PairingToken::first()->used_at);
        $this->postJson('/api/v1/devices/pair-with-token', $device)->assertUnprocessable();
    }

    public function test_expired_qr_cannot_pair_a_device(): void
    {
        [$admin] = $this->users();
        $this->activateTrial();
        config()->set('app.url', 'http://192.168.50.10:8000');
        RestaurantSetting::create(['restaurant_name' => 'QR Restaurant']);
        $table = DiningTable::create(['table_code' => 'T01', 'table_name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'is_active' => true]);
        $this->actingAs($admin)->post(route('admin.setup.pairing', $table));
        $payload = session('pairing_payload');
        PairingToken::query()->update(['expires_at' => now()->subSecond()]);

        $this->postJson('/api/v1/devices/pair-with-token', ['pairing_token' => $payload['pairing_token'], 'payload_signature' => $payload['payload_signature'], 'device_uuid' => 'dff6b0e8-2d84-4f45-b32c-cdd768ab1c02', 'device_name' => 'Expired Tablet'])->assertUnprocessable();
        $this->assertDatabaseCount('device_pairings', 0);
    }

    private function users(): array
    {
        $admin = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);
        $counter = Role::create(['name' => 'counter', 'display_name' => 'Counter']);
        return [
            User::create(['role_id' => $admin->id, 'name' => 'Admin', 'username' => 'admin', 'password' => 'secret-password', 'is_active' => true]),
            User::create(['role_id' => $counter->id, 'name' => 'Counter', 'username' => 'counter', 'password' => 'secret-password', 'is_active' => true]),
        ];
    }

    private function activateTrial(): void
    {
        app(EntitlementService::class)->activate(
            CommercialPlan::where('slug', 'trial')->firstOrFail(),
            'trial',
            30,
        );
    }
}
