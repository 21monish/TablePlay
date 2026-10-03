<?php

namespace Tests\Unit;

use App\Models\TablePlayInstallation;
use App\Services\LicenseSignatureService;
use App\Services\OfflineActivationRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfflineActivationRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private const FINGERPRINT_SEED = 'tableplay-offline-test-device';

    private OfflineActivationRequestService $requests;

    private TablePlayInstallation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tableplay.device_fingerprint_override', self::FINGERPRINT_SEED);
        config()->set('tableplay.offline_request_ttl_days', 30);
        $this->requests = app(OfflineActivationRequestService::class);
        $this->installation = TablePlayInstallation::query()->firstOrFail();
        $this->installation->update([
            'installation_uuid' => '00000000-0000-4000-8000-000000000101',
            'device_fingerprint' => null,
            'license_reference' => null,
        ]);
    }

    public function test_request_uses_real_ed25519_signature_and_persists_local_binding(): void
    {
        $this->travelTo('2030-01-02 03:04:05');

        $envelope = $this->requests->create($this->installation->fresh(), '2.4.0');
        $payload = $this->requests->validate($envelope);
        $installation = $this->installation->fresh();

        $this->assertSame(1, $envelope['version']);
        $this->assertSame(OfflineActivationRequestService::TYPE, $envelope['type']);
        $this->assertSame('Ed25519', $envelope['algorithm']);
        $this->assertTrue(Str::isUuid($payload['request_id']));
        $this->assertSame($installation->installation_uuid, $payload['installation_uuid']);
        $this->assertSame(hash('sha256', self::FINGERPRINT_SEED), $payload['device_fingerprint']);
        $this->assertSame('2.4.0', $payload['server_version']);
        $this->assertSame($this->requests->documentHash($envelope), $payload['request_hash']);
        $this->assertSame($payload['request_id'], $installation->offline_request_id);
        $this->assertSame($payload['request_hash'], $installation->offline_request_hash);
        $this->assertNotEmpty($installation->offline_request_public_key);
        $this->assertNotEmpty($installation->offline_request_private_key);

        $public = base64_decode($payload['request_public_key'], true);
        $signature = base64_decode($envelope['signature'], true);
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            $signature,
            app(LicenseSignatureService::class)->canonicalJson($envelope['payload']),
            $public,
        ));
    }

    public function test_request_contains_no_application_secret_or_activation_token(): void
    {
        $this->installation->update(['activation_token' => 'restaurant-secret-token']);
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('database.connections.mysql.password', 'database-secret-password');

        $json = json_encode($this->requests->create($this->installation->fresh(), '2.4.0'), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('restaurant-secret-token', $json);
        $this->assertStringNotContainsString('database-secret-password', $json);
        $this->assertStringNotContainsString((string) config('app.key'), $json);
        $this->assertStringNotContainsString((string) $this->installation->fresh()->offline_request_private_key, $json);
    }

    public function test_pending_request_is_idempotent_and_key_pair_is_stable_after_fulfilment(): void
    {
        $first = $this->requests->create($this->installation->fresh(), '2.4.0');
        $second = $this->requests->create($this->installation->fresh(), '2.4.1');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('local_offline_activation_requests', 1);

        \App\Models\LocalOfflineActivationRequest::where('request_id', $first['payload']['request_id'])
            ->update(['status' => 'fulfilled']);
        $third = $this->requests->create($this->installation->fresh(), '2.4.1');

        $this->assertNotSame($first['payload']['request_id'], $third['payload']['request_id']);
        $this->assertNotSame($this->requests->documentHash($first), $this->requests->documentHash($third));
        $this->assertSame($first['payload']['request_public_key'], $second['payload']['request_public_key']);
        $this->assertSame($first['payload']['request_public_key'], $third['payload']['request_public_key']);
    }

    public function test_payload_tampering_is_rejected(): void
    {
        $envelope = $this->requests->create($this->installation->fresh(), '2.4.0');
        $envelope['payload']['installation_uuid'] = (string) Str::uuid();

        $this->assertValidationError('activation_request', fn () => $this->requests->validate($envelope), 'modified');
    }

    public function test_signature_tampering_is_rejected(): void
    {
        $envelope = $this->requests->create($this->installation->fresh(), '2.4.0');
        $signature = base64_decode($envelope['signature'], true);
        $signature[0] = chr(ord($signature[0]) ^ 1);
        $envelope['signature'] = base64_encode($signature);

        $this->assertValidationError('activation_request', fn () => $this->requests->validate($envelope), 'signature');
    }

    public function test_expired_or_future_request_is_rejected(): void
    {
        $this->travelTo('2030-01-01 00:00:00');
        $expired = $this->requests->create($this->installation->fresh(), '2.4.0');

        $this->travelTo('2030-02-01 00:00:01');
        $this->assertValidationError('activation_request', fn () => $this->requests->validate($expired), 'expired');

        $this->travelTo('2030-01-01 00:00:00');
        $future = $this->requests->create($this->installation->fresh(), '2.4.0');
        $this->travelTo('2029-12-31 23:49:59');
        $this->assertValidationError('activation_request', fn () => $this->requests->validate($future), 'future');
    }

    public function test_licensed_installation_rejects_device_fingerprint_change(): void
    {
        $this->installation->update([
            'license_reference' => 'TP-LICENSED-DEVICE',
            'device_fingerprint' => hash('sha256', 'another-machine'),
        ]);

        $this->assertValidationError('activation_request', fn () => $this->requests->create($this->installation->fresh(), '2.4.0'), 'moved');
    }

    public function test_licensed_installation_rejects_damaged_local_request_identity(): void
    {
        $this->requests->create($this->installation->fresh(), '2.4.0');
        $this->installation->refresh()->update([
            'license_reference' => 'TP-LICENSED-DEVICE',
            'offline_request_private_key' => base64_encode('damaged'),
        ]);

        $this->assertValidationError('activation_request', fn () => $this->requests->create($this->installation->fresh(), '2.4.1'), 'damaged');
    }

    private function assertValidationError(string $key, callable $callback, ?string $messageContains = null): void
    {
        try {
            $callback();
            $this->fail("Expected a validation error for {$key}.");
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($key, $error->errors());
            if ($messageContains !== null) {
                $this->assertStringContainsStringIgnoringCase($messageContains, implode(' ', $error->errors()[$key]));
            }
        }
    }
}
