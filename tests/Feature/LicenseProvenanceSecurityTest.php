<?php

namespace Tests\Feature;

use App\Models\{CommercialPlan, RestaurantSubscription, TablePlayInstallation};
use App\Services\{EntitlementService, LicenseSignatureService, LocalLicenseService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicenseProvenanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $withTestLicense = false;

    private const INSTALLATION_UUID = '00000000-0000-4000-8000-000000000401';
    private const DEVICE_SEED = 'licence-provenance-security-device';

    public function test_fresh_installation_waits_for_a_cloud_authorized_license(): void
    {
        $state = app(EntitlementService::class)->state();

        $this->assertFalse($state['licensed']);
        $this->assertFalse($state['signature_valid']);
        $this->assertSame('missing', $state['status']);
        $this->assertNull($state['source']);
        $this->assertDatabaseCount('restaurant_subscriptions', 0);
        $this->assertFalse(app(EntitlementService::class)->allows('customer_app'));
    }

    public function test_valid_local_activation_and_service_status_changes_remain_authenticated(): void
    {
        $entitlements = app(EntitlementService::class);
        $subscription = $entitlements->activate(
            CommercialPlan::where('slug', 'premium')->firstOrFail(),
        );

        $payload = json_decode($subscription->signed_payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('tableplay-local-entitlement-v1', $payload['provenance']);
        $this->assertSame('local', $payload['source']);
        $this->assertTrue($entitlements->state()['signature_valid']);
        $this->assertTrue($entitlements->allows('advanced_reports'));

        $subscription = $entitlements->setLocalStatus($subscription, 'suspended');
        $this->assertTrue($entitlements->state()['signature_valid']);
        $this->assertSame('suspended', $entitlements->state()['status']);
        $this->assertFalse($entitlements->state()['licensed']);

        $entitlements->setLocalStatus($subscription, 'active');
        $this->assertTrue($entitlements->state()['signature_valid']);
        $this->assertTrue($entitlements->state()['licensed']);
    }

    public function test_source_downgrade_and_status_tampering_cannot_unlock_a_signed_cloud_license(): void
    {
        $subscription = $this->installCloudLicense('suspended');
        $entitlements = app(EntitlementService::class);

        $this->assertTrue($entitlements->state()['signature_valid']);
        $this->assertFalse($entitlements->state()['licensed']);

        // This was the original bypass: `local` used to skip signature checks,
        // allowing the same database update to turn a suspension into Premium.
        $subscription->update(['source' => 'local', 'status' => 'active']);
        $state = $entitlements->state();
        $this->assertFalse($state['signature_valid']);
        $this->assertFalse($state['licensed']);
        $this->assertSame([], $state['features']);
        $this->assertFalse($entitlements->allows('games'));

        $subscription->update(['source' => 'legacy']);
        $state = $entitlements->state();
        $this->assertFalse($state['signature_valid']);
        $this->assertFalse($state['licensed']);
    }

    public function test_switching_a_valid_online_license_to_offline_provenance_fails_closed(): void
    {
        $subscription = $this->installCloudLicense('active');
        $entitlements = app(EntitlementService::class);
        $this->assertTrue($entitlements->state()['licensed']);

        $subscription->update(['source' => 'offline']);
        $this->assertFalse($entitlements->state()['signature_valid']);
        $this->assertFalse($entitlements->state()['licensed']);

        $subscription->update(['source' => 'cloud']);
        $this->assertTrue($entitlements->state()['signature_valid']);
        $this->assertTrue($entitlements->state()['licensed']);
    }

    public function test_local_entitlement_or_status_database_edits_invalidate_the_hmac(): void
    {
        $entitlements = app(EntitlementService::class);
        $subscription = $entitlements->activate(
            CommercialPlan::where('slug', 'best')->firstOrFail(),
        );
        $this->assertTrue($entitlements->allows('games'));

        $features = $subscription->entitlement_snapshot;
        $features['advanced_reports'] = true;
        $subscription->update(['entitlement_snapshot' => $features]);

        $state = $entitlements->state();
        $this->assertFalse($state['signature_valid']);
        $this->assertFalse($state['licensed']);
        $this->assertFalse($entitlements->allows('advanced_reports'));

        $subscription = $entitlements->activate(
            CommercialPlan::where('slug', 'best')->firstOrFail(),
        );
        $subscription->update(['status' => 'suspended']);
        $this->assertFalse($entitlements->state()['signature_valid']);
        $this->assertFalse($entitlements->state()['licensed']);
    }

    private function installCloudLicense(string $status): RestaurantSubscription
    {
        $signatures = app(LicenseSignatureService::class);
        $keys = $signatures->generateKeyPair();
        config()->set('tableplay.license_key_id', 'provenance-test-v1');
        config()->set('tableplay.license_private_key', $keys['private_key']);
        config()->set('tableplay.license_public_key', $keys['public_key']);
        config()->set('tableplay.trusted_license_public_keys', ['provenance-test-v1' => $keys['public_key']]);
        config()->set('tableplay.device_fingerprint_override', self::DEVICE_SEED);

        $installation = TablePlayInstallation::query()->firstOrFail();
        $installation->update([
            'installation_uuid' => self::INSTALLATION_UUID,
            'device_fingerprint' => null,
            'license_reference' => null,
            'license_revision' => 0,
            'license_content_hash' => null,
        ]);
        $installation = app(LocalLicenseService::class)->installation();
        $plan = CommercialPlan::where('slug', 'premium')->firstOrFail();
        $payload = [
            'license_uuid' => '00000000-0000-4000-8000-000000000402',
            'license_reference' => 'TP-CLOUD-PROVENANCE',
            'license_revision' => 1,
            'restaurant_uuid' => '00000000-0000-4000-8000-000000000403',
            'restaurant_name' => 'Provenance Test Restaurant',
            'installation_uuid' => self::INSTALLATION_UUID,
            'device_fingerprint' => hash('sha256', self::DEVICE_SEED),
            'activation_method' => 'online',
            'plan' => $plan->slug,
            'status' => $status,
            'features' => $plan->features,
            'limits' => ['paired_tables' => null],
            'max_paired_tables' => null,
            'issued_at' => now()->toIso8601String(),
            'starts_at' => now()->subDay()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'grace_ends_at' => now()->addYear()->addDays(7)->toIso8601String(),
        ];

        return app(LocalLicenseService::class)->installEnvelope($signatures->sign($payload), 'cloud');
    }
}
