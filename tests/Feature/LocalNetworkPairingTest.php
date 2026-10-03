<?php

namespace Tests\Feature;

use App\Models\{CommercialPlan, DiningTable, RestaurantSetting, Role, User};
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalNetworkPairingTest extends TestCase
{
    use RefreshDatabase;

    public function test_loopback_admin_url_never_leaks_into_customer_or_staff_qr(): void
    {
        [$admin, $table] = $this->restaurant();
        config()->set('app.url', 'http://10.69.104.253:8000');

        $this->actingAs($admin)->post('http://127.0.0.1:8000/admin/setup/pairing/'.$table->id)
            ->assertRedirect(route('admin.setup.index'));
        $customer = session('pairing_payload');

        $this->assertSame(2, $customer['version']);
        $this->assertSame('http://10.69.104.253:8000/api/v1', $customer['api_base_url']);
        $this->assertSame('ws://10.69.104.253:8080', $customer['reverb_url']);
        $this->assertStringNotContainsString('127.0.0.1', json_encode($customer));
        $this->assertSame(64, strlen($customer['payload_signature']));

        $this->actingAs($admin)->post('http://localhost:8000/admin/setup/staff-connection')
            ->assertRedirect(route('admin.setup.index'));
        $staff = session('staff_connection_payload');
        $this->assertSame('tableplay_staff_connection', $staff['type']);
        $this->assertSame('http://10.69.104.253:8000/api/v1', $staff['api_base_url']);
        $this->assertSame(64, strlen($staff['signature']));
    }

    public function test_pairing_rejects_a_tampered_qr_signature(): void
    {
        [$admin, $table] = $this->restaurant();
        config()->set('app.url', 'http://192.168.10.20:8000');
        $this->actingAs($admin)->post(route('admin.setup.pairing', $table));
        $payload = session('pairing_payload');

        $this->postJson('/api/v1/devices/pair-with-token', [
            'pairing_token' => $payload['pairing_token'],
            'payload_signature' => str_repeat('0', 64),
            'device_uuid' => '00000000-0000-4000-8000-000000000991',
            'device_name' => 'Tampered tablet',
        ])->assertUnprocessable()->assertJsonValidationErrors('pairing_token');

        $this->assertDatabaseCount('device_pairings', 0);
    }

    private function restaurant(): array
    {
        $role = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);
        $admin = User::create(['role_id' => $role->id, 'name' => 'Admin', 'username' => 'network-admin', 'password' => 'secret-password', 'is_active' => true]);
        RestaurantSetting::create(['restaurant_name' => 'Ganesh Restaurant']);
        app(EntitlementService::class)->activate(CommercialPlan::where('slug', 'trial')->firstOrFail(), 'trial', 30);
        $table = DiningTable::create(['table_code' => 'T01', 'table_name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'is_active' => true]);
        return [$admin, $table];
    }
}
