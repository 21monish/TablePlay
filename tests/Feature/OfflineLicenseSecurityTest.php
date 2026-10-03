<?php

namespace Tests\Feature;

use App\Models\{CommercialPlan, LocalOfflineActivationRequest, RestaurantSubscription, TablePlayInstallation};
use App\Services\{EntitlementService, LicenseSignatureService, LocalLicenseService, OfflineActivationRequestService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfflineLicenseSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALLATION_UUID = '00000000-0000-4000-8000-000000000201';
    private const DEVICE_SEED = 'offline-license-security-device';

    private LicenseSignatureService $signatures;

    private LocalLicenseService $licenses;

    private TablePlayInstallation $installation;

    private CommercialPlan $plan;

    private string $requestId;

    private string $requestHash;

    private string $requestPublicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-01-01 12:00:00');
        $this->signatures = app(LicenseSignatureService::class);
        $keys = $this->signatures->generateKeyPair();
        config()->set('tableplay.license_key_id', 'offline-test-v1');
        config()->set('tableplay.license_private_key', $keys['private_key']);
        config()->set('tableplay.license_public_key', $keys['public_key']);
        config()->set('tableplay.trusted_license_public_keys', ['offline-test-v1' => $keys['public_key']]);
        config()->set('tableplay.device_fingerprint_override', self::DEVICE_SEED);

        $this->licenses = app(LocalLicenseService::class);
        $this->installation = TablePlayInstallation::query()->firstOrFail();
        $this->installation->update([
            'installation_uuid' => self::INSTALLATION_UUID,
            'device_fingerprint' => null,
            'license_reference' => null,
            'license_revision' => 0,
            'license_content_hash' => null,
        ]);
        $requestEnvelope = app(OfflineActivationRequestService::class)->create($this->installation->fresh(), '2.4.0');
        $requestPayload = app(OfflineActivationRequestService::class)->validate($requestEnvelope);
        $this->requestId = $requestPayload['request_id'];
        $this->requestHash = $requestPayload['request_hash'];
        $this->requestPublicKey = $requestPayload['request_public_key'];
        $this->assertDatabaseHas('local_offline_activation_requests', [
            'request_id' => $this->requestId,
            'installation_uuid' => self::INSTALLATION_UUID,
            'request_hash' => $this->requestHash,
            'status' => 'pending',
        ]);
        $this->plan = CommercialPlan::where('slug', 'best')->firstOrFail();
    }

    public function test_valid_offline_license_is_bound_installed_and_idempotent(): void
    {
        $envelope = $this->signedEnvelope();

        $first = $this->licenses->import(json_encode($envelope, JSON_THROW_ON_ERROR));
        $second = $this->licenses->import(json_encode($envelope, JSON_THROW_ON_ERROR));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('offline', $second->source);
        $this->assertSame(1, $second->license_revision);
        $this->assertSame(5, data_get($second->entitlement_limits, 'paired_tables'));
        $this->assertDatabaseCount('restaurant_subscriptions', 2);
        $this->assertDatabaseHas('local_offline_activation_requests', [
            'request_id' => $this->requestId,
            'status' => 'fulfilled',
            'fulfilled_revision' => 1,
        ]);

        $installation = $this->installation->fresh();
        $this->assertSame(1, $installation->license_revision);
        $this->assertSame($second->license_reference, $installation->license_reference);
        $this->assertNotEmpty($installation->license_content_hash);

        $storedPayload = json_decode($second->signed_payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($this->signatures->verify([
            'version' => 1,
            'algorithm' => 'Ed25519',
            'key_id' => $second->license_key_id,
            'payload' => $storedPayload,
            'signature' => $second->signature,
        ]));
        $this->assertSame($second->entitlement_snapshot, $storedPayload['features']);
        $this->assertSame($second->entitlement_limits, $storedPayload['limits']);
        $this->assertSame($second->starts_at->getTimestamp(), \Illuminate\Support\Carbon::parse($storedPayload['starts_at'])->getTimestamp());
        $this->assertSame($second->expires_at->getTimestamp(), \Illuminate\Support\Carbon::parse($storedPayload['expires_at'])->getTimestamp());
        $this->assertSame($second->grace_ends_at->getTimestamp(), \Illuminate\Support\Carbon::parse($storedPayload['grace_ends_at'])->getTimestamp());

        $state = app(EntitlementService::class)->state();
        $this->assertTrue($state['licensed']);
        $this->assertTrue($state['signature_valid']);
        $this->assertSame('best', data_get($state, 'plan.slug'));
        $this->assertSame(5, data_get($state, 'limits.paired_tables'));
    }

    public function test_payload_and_signature_tampering_are_rejected_without_changing_current_license(): void
    {
        $current = RestaurantSubscription::latest('id')->firstOrFail()->only(['id', 'status', 'license_reference']);
        $payloadTampered = $this->signedEnvelope();
        $payloadTampered['payload']['features']['premium_support'] = true;
        $this->assertLicenseRejected($payloadTampered);

        $signatureTampered = $this->signedEnvelope();
        $signature = base64_decode($signatureTampered['signature'], true);
        $signature[0] = chr(ord($signature[0]) ^ 1);
        $signatureTampered['signature'] = base64_encode($signature);
        $this->assertLicenseRejected($signatureTampered);

        $this->assertSame($current, RestaurantSubscription::latest('id')->firstOrFail()->only(['id', 'status', 'license_reference']));
        $this->assertSame(0, $this->installation->fresh()->license_revision);
    }

    public function test_clock_rollback_disables_signed_entitlements(): void
    {
        $this->licenses->import(json_encode($this->signedEnvelope(), JSON_THROW_ON_ERROR));
        $this->travelBack();

        $state = app(EntitlementService::class)->state();

        $this->assertFalse($state['licensed']);
        $this->assertSame('invalid', $state['status']);
        $this->assertStringContainsStringIgnoringCase('clock', data_get($state, 'warnings.0.message'));
    }

    public function test_wrong_installation_device_or_request_binding_is_rejected(): void
    {
        $this->assertLicenseRejected($this->signedEnvelope(['installation_uuid' => (string) Str::uuid()]));
        $this->assertLicenseRejected($this->signedEnvelope(['device_fingerprint' => hash('sha256', 'other-device')]));
        $this->assertLicenseRejected($this->signedEnvelope(['offline_request_id' => (string) Str::uuid()]));
        $this->assertLicenseRejected($this->signedEnvelope(['offline_request_hash' => str_repeat('b', 64)]));
    }

    public function test_older_revision_and_conflicting_same_revision_cannot_roll_back_license(): void
    {
        $revisionTwo = $this->signedEnvelope(['license_revision' => 2, 'license_uuid' => (string) Str::uuid()]);
        $installed = $this->licenses->import(json_encode($revisionTwo, JSON_THROW_ON_ERROR));

        $this->assertLicenseRejected($this->signedEnvelope(['license_revision' => 1]));
        $this->assertLicenseRejected($this->signedEnvelope([
            'license_revision' => 2,
            'license_uuid' => (string) Str::uuid(),
            'status' => 'suspended',
        ]));

        $this->assertSame($installed->license_reference, $this->installation->fresh()->license_reference);
        $this->assertSame(2, $this->installation->fresh()->license_revision);
        $this->assertSame('active', RestaurantSubscription::findOrFail($installed->id)->status);
    }

    public function test_newer_suspended_license_is_imported_and_disables_all_entitlements(): void
    {
        $this->licenses->import(json_encode($this->signedEnvelope(), JSON_THROW_ON_ERROR));
        $suspended = $this->signedEnvelope([
            'license_uuid' => (string) Str::uuid(),
            'license_revision' => 2,
            'status' => 'suspended',
            'issued_at' => now()->addMinute()->toIso8601String(),
        ]);

        $this->licenses->import(json_encode($suspended, JSON_THROW_ON_ERROR));
        $state = app(EntitlementService::class)->state();

        $this->assertSame('suspended', $state['status']);
        $this->assertFalse($state['licensed']);
        $this->assertFalse(app(EntitlementService::class)->allows('games'));
        $this->assertFalse(app(EntitlementService::class)->allows('customer_app'));
    }

    public function test_expired_license_is_imported_as_authoritative_but_cannot_unlock_features(): void
    {
        $envelope = $this->signedEnvelope([
            'status' => 'expired',
            'starts_at' => now()->subMonths(2)->toIso8601String(),
            'expires_at' => now()->subMonth()->toIso8601String(),
            'grace_ends_at' => now()->subDay()->toIso8601String(),
        ]);

        $this->licenses->import(json_encode($envelope, JSON_THROW_ON_ERROR));
        $state = app(EntitlementService::class)->state();

        $this->assertSame('expired', $state['status']);
        $this->assertFalse($state['licensed']);
        $this->assertFalse(app(EntitlementService::class)->allows('games'));
    }

    public function test_unknown_status_plan_and_invalid_date_order_are_rejected(): void
    {
        $this->assertLicenseRejected($this->signedEnvelope(['status' => 'administrator']));
        $this->assertLicenseRejected($this->signedEnvelope(['plan' => 'not-a-real-plan']));
        $this->assertLicenseRejected($this->signedEnvelope([
            'starts_at' => now()->addMonth()->toIso8601String(),
            'expires_at' => now()->toIso8601String(),
        ]));
        $this->assertLicenseRejected($this->signedEnvelope([
            'expires_at' => now()->addMonth()->toIso8601String(),
            'grace_ends_at' => now()->toIso8601String(),
        ]));
    }

    public function test_missing_or_malformed_public_key_fails_closed_and_preserves_trial(): void
    {
        $envelope = $this->signedEnvelope();
        $before = RestaurantSubscription::latest('id')->firstOrFail()->only(['id', 'status', 'license_reference']);

        config()->set('tableplay.license_public_key', null);
        config()->set('tableplay.trusted_license_public_keys', []);
        $this->assertLicenseRejected($envelope);

        config()->set('tableplay.license_public_key', 'malformed');
        config()->set('tableplay.trusted_license_public_keys', ['offline-test-v1' => 'malformed']);
        $this->assertLicenseRejected($envelope);

        $this->assertSame($before, RestaurantSubscription::latest('id')->firstOrFail()->only(['id', 'status', 'license_reference']));
    }

    private function signedEnvelope(array $overrides = []): array
    {
        $payload = array_merge([
            'license_uuid' => '00000000-0000-4000-8000-000000000203',
            'license_reference' => 'TP-OFFLINE-SECURITY',
            'license_revision' => 1,
            'restaurant_uuid' => '00000000-0000-4000-8000-000000000204',
            'restaurant_name' => 'Offline Test Cafe',
            'installation_uuid' => self::INSTALLATION_UUID,
            'device_fingerprint' => hash('sha256', self::DEVICE_SEED),
            'activation_method' => 'offline',
            'offline_request_id' => $this->requestId,
            'offline_request_hash' => $this->requestHash,
            'request_public_key' => $this->requestPublicKey,
            'plan' => $this->plan->slug,
            'status' => 'active',
            'features' => $this->plan->features,
            'limits' => ['paired_tables' => 5],
            'max_paired_tables' => 5,
            'issued_at' => now()->toIso8601String(),
            'starts_at' => now()->subDay()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'grace_ends_at' => now()->addYear()->addDays(7)->toIso8601String(),
        ], $overrides);

        return $this->signatures->sign($payload);
    }

    private function assertLicenseRejected(array $envelope): void
    {
        try {
            $this->licenses->import(json_encode($envelope, JSON_THROW_ON_ERROR));
            $this->fail('Expected the offline licence to be rejected.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
    }
}
