<?php

namespace Tests\Feature;

use App\Models\{AuditLog, CloudInstallation, CloudRestaurant, CloudSubscription, CommercialPlan, Role, User};
use App\Services\LicenseSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CloudInstallationTransferTest extends TestCase
{
    use RefreshDatabase;

    protected bool $withTestLicense = false;

    protected function setUp(): void
    {
        parent::setUp();
        $keys = app(LicenseSignatureService::class)->generateKeyPair();
        config()->set([
            'tableplay.mode' => 'cloud', 'tableplay.license_key_id' => 'transfer-test',
            'tableplay.license_public_key' => $keys['public_key'],
            'tableplay.license_private_key' => $keys['private_key'],
        ]);
    }

    public function test_transfer_control_renders_identity_and_custom_confirmation_without_token_hash(): void
    {
        [$subscription, $installation] = $this->installation();
        $this->actingAs($this->user())->get(route('superadmin.cloud.index'))
            ->assertOk()->assertSee('Server installations')->assertSee($installation->installation_uuid)
            ->assertSee(route('superadmin.cloud.installations.transfer', $installation), false)
            ->assertSee('Transfer installation')->assertSee('data-confirm-title="Transfer this installation?"', false)
            ->assertSee('Subscription expiry and grace dates will not change.')
            ->assertDontSee($installation->activation_token_hash);
        $installation->update(['status' => 'transferred']);
        $this->get(route('superadmin.cloud.index'))->assertOk()->assertSee('No transfer available')->assertDontSee('>Transfer installation</button>', false);
    }

    public function test_transfer_frees_slot_and_replacement_activates_without_extending_grace_or_expiry(): void
    {
        [$subscription, $installation] = $this->installation();
        $before = $subscription->fresh()->getAttributes();
        $this->actingAs($this->user())->post(route('superadmin.cloud.installations.transfer', $installation))
            ->assertRedirect()->assertSessionHas('issued_license_key');
        $key = session('issued_license_key');
        $this->assertSame($before, $subscription->fresh()->getAttributes());
        $this->assertSame('transferred', $installation->fresh()->status);
        $this->assertNotNull($installation->fresh()->deactivated_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cloud_installation.transferred', 'entity_id' => $installation->id]);
        $this->assertStringNotContainsString($key, AuditLog::latest('id')->firstOrFail()->toJson());
        $this->withToken('old-test-token')->postJson('/api/cloud/v1/licenses/sync', [
            'installation_uuid' => $installation->installation_uuid, 'device_fingerprint' => $installation->device_fingerprint,
        ])->assertUnauthorized();
        $replacement = $this->postJson('/api/cloud/v1/licenses/activate', [
            'license_key' => $key, 'installation_uuid' => (string) Str::uuid(),
            'device_name' => 'Replacement server', 'device_fingerprint' => hash('sha256', 'replacement'), 'server_version' => '2.5.8',
        ])->assertOk()->assertJsonPath('license.payload.status', 'grace');
        $this->assertTrue(app(LicenseSignatureService::class)->verify($replacement->json('license')));
        $this->assertSame($subscription->expires_at->toIso8601String(), $replacement->json('license.payload.subscription_expires_at'));
        $this->assertSame($subscription->grace_ends_at->toIso8601String(), $replacement->json('license.payload.grace_ends_at'));
        $this->assertSame(1, $subscription->installations()->where('status', 'active')->count());
        $this->assertSame($before, $subscription->fresh()->getAttributes());
    }

    public function test_repeat_transfer_does_not_issue_another_key(): void
    {
        [, $installation] = $this->installation();
        $admin = $this->user();
        $this->actingAs($admin)->post(route('superadmin.cloud.installations.transfer', $installation))->assertRedirect();
        $this->post(route('superadmin.cloud.installations.transfer', $installation))->assertSessionHasErrors('installation');
        $this->assertDatabaseCount('cloud_license_keys', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_expired_or_suspended_subscription_cannot_transfer(): void
    {
        [$subscription, $installation] = $this->installation();
        $this->actingAs($this->user());
        foreach (['expired', 'suspended'] as $status) {
            $subscription->update(['status' => $status]);
            $this->post(route('superadmin.cloud.installations.transfer', $installation))->assertSessionHasErrors('installation');
            $this->assertSame('active', $installation->fresh()->status);
        }
        $subscription->update(['status' => 'grace', 'grace_ends_at' => now()->subMinute()]);
        $this->post(route('superadmin.cloud.installations.transfer', $installation))->assertSessionHasErrors('installation');
        $this->assertDatabaseCount('cloud_license_keys', 0);
    }

    public function test_missing_signing_configuration_keeps_old_installation_active(): void
    {
        [, $installation] = $this->installation();
        config()->set('tableplay.license_private_key', '');
        $this->actingAs($this->user())->post(route('superadmin.cloud.installations.transfer', $installation))->assertSessionHasErrors('installation');
        $this->assertSame('active', $installation->fresh()->status);
        $this->assertDatabaseCount('cloud_license_keys', 0);
    }

    public function test_audit_failure_rolls_back_key_and_deactivation(): void
    {
        [, $installation] = $this->installation();
        $this->mock(\App\Services\AuditService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Test audit failure'));
        $this->actingAs($this->user())->post(route('superadmin.cloud.installations.transfer', $installation))->assertServerError();
        $this->assertSame('active', $installation->fresh()->status);
        $this->assertDatabaseCount('cloud_license_keys', 0);
    }

    public function test_guest_and_restaurant_admin_cannot_transfer(): void
    {
        [, $installation] = $this->installation();
        $this->post(route('superadmin.cloud.installations.transfer', $installation))->assertRedirect(route('login'));
        $this->actingAs($this->user('admin'))->post(route('superadmin.cloud.installations.transfer', $installation))->assertForbidden();
        $this->assertSame('active', $installation->fresh()->status);
        $this->assertDatabaseCount('cloud_license_keys', 0);
    }

    private function installation(): array
    {
        $restaurant = CloudRestaurant::create(['restaurant_uuid' => (string) Str::uuid(), 'name' => 'Transfer test restaurant', 'status' => 'active']);
        $subscription = CloudSubscription::create([
            'cloud_restaurant_id' => $restaurant->id, 'commercial_plan_id' => CommercialPlan::where('slug', 'trial')->firstOrFail()->id,
            'license_reference' => 'TP-'.Str::upper(Str::random(14)), 'status' => 'grace',
            'starts_at' => now()->subDays(20), 'expires_at' => now()->subDays(5), 'grace_ends_at' => now()->addDays(2),
            'max_installations' => 1, 'license_revision' => 2,
        ]);
        $installation = CloudInstallation::create([
            'cloud_subscription_id' => $subscription->id, 'installation_uuid' => (string) Str::uuid(),
            'device_name' => 'Old server', 'device_fingerprint' => hash('sha256', 'old server'),
            'activation_token_hash' => hash('sha256', 'old-test-token'), 'activation_method' => 'online',
            'status' => 'active', 'activated_at' => now()->subDays(10), 'license_revision' => 2,
        ]);
        return [$subscription, $installation];
    }

    private function user(string $role = 'superadmin'): User
    {
        $role = Role::firstOrCreate(['name' => $role], ['display_name' => ucfirst($role)]);
        return User::create(['role_id' => $role->id, 'name' => 'Transfer tester', 'username' => 'transfer'.Str::random(8), 'password' => 'test-only-password', 'is_active' => true]);
    }
}
