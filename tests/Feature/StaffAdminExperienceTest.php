<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Device, DevicePairing, DiningTable, LicenseSyncLog, PairingToken, RestaurantSetting, Role, TablePlayInstallation, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffAdminExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'http://192.168.50.10:8000');
        RestaurantSetting::create(['restaurant_name' => 'Experience Restaurant']);
    }

    public function test_new_admin_pages_require_authentication_and_reject_other_staff_roles(): void
    {
        foreach (['setup', 'license'] as $page) {
            $this->getJson('/api/v1/admin/'.$page)->assertUnauthorized();
        }
        foreach (['counter', 'kitchen', 'waiter', 'superadmin'] as $role) {
            Sanctum::actingAs($this->user($role));
            foreach (['setup', 'license'] as $page) {
                $this->getJson('/api/v1/admin/'.$page)->assertForbidden();
            }
            foreach (['setup/staff-connection', 'license/activate', 'license/sync', 'license/import', 'license/offline-request'] as $path) {
                $this->postJson('/api/v1/admin/'.$path)->assertForbidden();
            }
        }
    }

    public function test_setup_and_staff_connection_use_a_tablet_reachable_address_without_login_credentials(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('http://192.168.50.10:8000/api/v1/admin/setup')->assertOk()
            ->assertJsonPath('connection.api_base_url', 'http://192.168.50.10:8000/api/v1')
            ->assertJsonStructure(['steps', 'completed', 'total', 'percent', 'ready', 'releases']);
        $response = $this->postJson('http://192.168.50.10:8000/api/v1/admin/setup/staff-connection')->assertOk()
            ->assertJsonPath('type', 'tableplay_staff_connection')
            ->assertJsonPath('api_base_url', 'http://192.168.50.10:8000/api/v1');
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertArrayNotHasKey('pairing_token', $response->json());
    }

    public function test_staff_qr_pairs_once_and_never_records_its_secret_in_audit_history(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $table = $this->table('T01');
        $response = $this->postJson('/api/v1/admin/tables/'.$table->id.'/pairing-qr')->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('type', 'tableplay_pairing');
        $payload = $response->json();
        $this->assertSame(hash('sha256', $payload['pairing_token']), PairingToken::first()->token_hash);
        $this->assertStringNotContainsString($payload['pairing_token'], AuditLog::where('action', 'pairing.qr.issued.mobile')->first()->toJson());
        $body = ['pairing_token' => $payload['pairing_token'], 'payload_signature' => $payload['payload_signature'], 'device_uuid' => 'aaa00000-0000-4000-8000-000000000001', 'device_name' => 'Customer tablet'];
        $this->postJson('/api/v1/devices/pair-with-token', $body)->assertCreated();
        $this->postJson('/api/v1/devices/pair-with-token', $body)->assertUnprocessable();
        $this->postJson('/api/v1/admin/tables/'.$table->id.'/pairing-qr')->assertUnprocessable();
    }

    public function test_pairing_qr_rejects_disabled_tables_and_enforces_the_trial_limit(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $disabled = $this->table('DISABLED');
        $disabled->update(['is_active' => false]);
        $this->postJson('/api/v1/admin/tables/'.$disabled->id.'/pairing-qr')->assertUnprocessable();
        for ($number = 1; $number <= 3; $number++) {
            $table = $this->table('T'.$number);
            $device = Device::create(['device_uuid' => 'limit-device-'.$number, 'device_name' => 'Tablet '.$number, 'device_type' => 'tablet', 'is_active' => true]);
            DevicePairing::create(['device_id' => $device->id, 'dining_table_id' => $table->id, 'is_active' => true, 'paired_at' => now()]);
        }
        $extra = $this->table('T04');
        $this->postJson('/api/v1/admin/tables/'.$extra->id.'/pairing-qr')->assertUnprocessable()->assertJsonValidationErrors('license');
        $this->assertDatabaseCount('pairing_tokens', 0);
    }

    public function test_licence_response_separates_dates_and_does_not_leak_private_material(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $installation = TablePlayInstallation::firstOrFail();
        $installation->update(['activation_token' => 'private-cloud-token', 'offline_request_private_key' => 'private-request-key']);
        LicenseSyncLog::create(['installation_uuid' => $installation->installation_uuid, 'event' => 'local.sync', 'status' => 'success', 'metadata' => ['secret' => 'private-metadata']]);
        $response = $this->getJson('/api/v1/admin/license')->assertOk()
            ->assertJsonPath('entitlements.signature_valid', true)
            ->assertJsonPath('entitlements.days_remaining', 30)
            ->assertJsonPath('installation.online_activated', true)
            ->assertJsonStructure(['entitlements' => ['subscription_expires_at', 'offline_verification_due_at', 'days_remaining'], 'sync_logs']);
        foreach (['private-cloud-token', 'private-request-key', 'private-metadata', 'activation_token', 'offline_request_private_key', 'signed_license_payload'] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_bad_activation_and_import_are_validated_without_replacing_the_licence(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $before = app(\App\Services\EntitlementService::class)->subscription()->toJson();
        $this->postJson('/api/v1/admin/license/activate', ['cloud_url' => 'file:///etc/passwd', 'license_key' => ''])->assertUnprocessable()->assertJsonValidationErrors(['cloud_url', 'license_key']);
        $this->postJson('/api/v1/admin/license/import', ['license_file' => UploadedFile::fake()->createWithContent('invalid.tpl', '{"payload": {"plan": "best"}}')])->assertUnprocessable();
        $this->postJson('/api/v1/admin/license/import', ['license_file' => UploadedFile::fake()->create('large.tpl', 65)])->assertUnprocessable();
        $this->assertSame($before, app(\App\Services\EntitlementService::class)->subscription()->toJson());
    }

    public function test_sync_failure_is_recoverable_and_offline_request_contains_no_passwords(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $before = app(\App\Services\EntitlementService::class)->subscription()->id;
        $this->postJson('/api/v1/admin/license/sync')->assertUnprocessable()->assertJsonValidationErrors('license');
        $response = $this->postJson('/api/v1/admin/license/offline-request')->assertOk()->assertJsonStructure(['filename', 'document']);
        $this->assertStringEndsWith('.tpr', $response->json('filename'));
        $response->assertDontSee('private_key')->assertDontSee('password')->assertDontSee('activation_token');
        $this->assertSame($before, app(\App\Services\EntitlementService::class)->subscription()->id);
    }

    private function user(string $name): User
    {
        $role = Role::firstOrCreate(['name' => $name], ['display_name' => ucfirst($name)]);
        return User::create(['role_id' => $role->id, 'name' => ucfirst($name), 'username' => 'experience-'.$name, 'password' => 'test-only-password', 'is_active' => true]);
    }

    private function table(string $code): DiningTable
    {
        return DiningTable::create(['table_code' => $code, 'table_name' => 'Table '.$code, 'capacity' => 4, 'status' => 'available', 'is_active' => true]);
    }
}
