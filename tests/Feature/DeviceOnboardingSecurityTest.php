<?php

namespace Tests\Feature;

use App\Models\{Device, DiningTable, PairingToken, Role, User};
use App\Services\SecurePairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceOnboardingSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_registration_and_table_code_pairing_cannot_create_or_take_over_a_device(): void
    {
        $existing = Device::create([
            'device_uuid' => (string) Str::uuid(),
            'device_name' => 'Dining room tablet',
            'device_type' => 'tablet',
            'is_active' => true,
        ]);
        $token = $existing->createToken('tablet')->plainTextToken;

        $this->postJson('/api/v1/devices/register', [
            'device_uuid' => $existing->device_uuid,
            'device_name' => 'Attacker replacement',
            'device_type' => 'tablet',
        ])->assertGone()
            ->assertJsonPath('error', 'secure_pairing_required');

        $this->assertSame('Dining room tablet', $existing->fresh()->device_name);
        $this->assertSame(1, $existing->tokens()->count());

        $this->postJson('/api/v1/devices/register', [
            'device_uuid' => (string) Str::uuid(),
            'device_name' => 'Unapproved tablet',
            'device_type' => 'tablet',
        ])->assertGone();
        $this->assertDatabaseCount('devices', 1);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Device-UUID' => $existing->device_uuid,
        ])->postJson('/api/v1/devices/pair', ['table_code' => 'T01'])
            ->assertGone()
            ->assertJsonPath('error', 'secure_pairing_required');

        $this->assertDatabaseCount('device_pairings', 0);
    }

    public function test_secure_qr_pairs_a_new_tablet_but_cannot_replace_an_already_paired_uuid(): void
    {
        $admin = $this->admin();
        $firstTable = DiningTable::create([
            'table_code' => 'T01', 'table_name' => 'Table 1', 'capacity' => 4,
            'status' => 'available', 'is_active' => true,
        ]);
        $secondTable = DiningTable::create([
            'table_code' => 'T02', 'table_name' => 'Table 2', 'capacity' => 4,
            'status' => 'available', 'is_active' => true,
        ]);
        $uuid = (string) Str::uuid();

        $firstQr = app(SecurePairingService::class)->issue($firstTable, $admin->id, 'http://tableplay.test/api/v1');
        $first = $this->postJson('/api/v1/devices/pair-with-token', [
            'pairing_token' => $firstQr['pairing_token'],
            'payload_signature' => $firstQr['payload_signature'],
            'device_uuid' => $uuid,
            'device_name' => 'Table 1 tablet',
            'app_version' => '1.9.1',
        ])->assertCreated()
            ->assertJsonPath('table_code', 'T01');

        $deviceId = $first->json('device.id');
        $originalToken = $first->json('token');
        $secondQr = app(SecurePairingService::class)->issue($secondTable, $admin->id, 'http://tableplay.test/api/v1');

        $this->postJson('/api/v1/devices/pair-with-token', [
            'pairing_token' => $secondQr['pairing_token'],
            'payload_signature' => $secondQr['payload_signature'],
            'device_uuid' => $uuid,
            'device_name' => 'Replacement tablet',
            'app_version' => '9.9.9',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('device_uuid');

        $this->assertDatabaseHas('devices', [
            'id' => $deviceId,
            'device_name' => 'Table 1 tablet',
            'app_version' => '1.9.1',
        ]);
        $this->assertDatabaseHas('device_pairings', [
            'device_id' => $deviceId,
            'dining_table_id' => $firstTable->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('device_pairings', [
            'device_id' => $deviceId,
            'dining_table_id' => $secondTable->id,
            'is_active' => true,
        ]);
        $this->assertNull(PairingToken::where('dining_table_id', $secondTable->id)->firstOrFail()->used_at);
        $this->assertSame(1, Device::findOrFail($deviceId)->tokens()->count());

        auth()->forgetGuards();
        $this->withHeaders([
            'Authorization' => 'Bearer '.$originalToken,
            'X-Device-UUID' => $uuid,
        ])->postJson('/api/v1/devices/heartbeat')->assertOk()->assertJsonPath('ok', true);
    }

    private function admin(): User
    {
        $role = Role::create(['name' => 'admin', 'display_name' => 'Administrator']);

        return User::create([
            'role_id' => $role->id,
            'name' => 'Admin',
            'username' => 'admin-security',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
    }
}
