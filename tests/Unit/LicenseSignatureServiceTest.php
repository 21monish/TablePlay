<?php

namespace Tests\Unit;

use App\Services\LicenseSignatureService;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class LicenseSignatureServiceTest extends TestCase
{
    private LicenseSignatureService $signatures;

    private array $primary;

    private array $previous;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signatures = app(LicenseSignatureService::class);
        $this->primary = $this->signatures->generateKeyPair();
        $this->previous = $this->signatures->generateKeyPair();

        config()->set('tableplay.license_key_id', 'market-v2');
        config()->set('tableplay.license_private_key', $this->primary['private_key']);
        config()->set('tableplay.license_public_key', $this->primary['public_key']);
        config()->set('tableplay.trusted_license_public_keys', [
            'market-v1' => $this->previous['public_key'],
            'market-v2' => $this->primary['public_key'],
        ]);
    }

    public function test_real_ed25519_signature_verifies_and_is_canonical(): void
    {
        $payload = [
            'zebra' => ['second' => 2, 'first' => 1],
            'alpha' => 'TablePlay',
            'features' => ['games' => true, 'staff_apps' => true],
        ];

        $envelope = $this->signatures->sign($payload);

        $this->assertSame('market-v2', $envelope['key_id']);
        $this->assertTrue($this->signatures->verify($envelope));
        $this->assertSame(
            $this->signatures->canonicalJson($payload),
            $this->signatures->canonicalJson([
                'features' => ['staff_apps' => true, 'games' => true],
                'alpha' => 'TablePlay',
                'zebra' => ['first' => 1, 'second' => 2],
            ]),
        );
    }

    public function test_payload_or_signature_tampering_fails_closed(): void
    {
        $envelope = $this->signatures->sign(['plan' => 'best', 'status' => 'active']);

        $tamperedPayload = $envelope;
        $tamperedPayload['payload']['plan'] = 'premium';
        $this->assertFalse($this->signatures->verify($tamperedPayload));

        $tamperedSignature = $envelope;
        $signature = base64_decode($tamperedSignature['signature'], true);
        $signature[0] = chr(ord($signature[0]) ^ 1);
        $tamperedSignature['signature'] = base64_encode($signature);
        $this->assertFalse($this->signatures->verify($tamperedSignature));
    }

    public function test_key_id_selects_the_matching_trusted_key_during_rotation(): void
    {
        config()->set('tableplay.license_key_id', 'market-v1');
        config()->set('tableplay.license_private_key', $this->previous['private_key']);
        config()->set('tableplay.license_public_key', $this->previous['public_key']);
        $oldEnvelope = $this->signatures->sign(['plan' => 'best']);

        config()->set('tableplay.license_key_id', 'market-v2');
        config()->set('tableplay.license_private_key', $this->primary['private_key']);
        config()->set('tableplay.license_public_key', $this->primary['public_key']);

        $this->assertTrue($this->signatures->verify($oldEnvelope));

        config()->set('tableplay.trusted_license_public_keys', ['market-v2' => $this->primary['public_key']]);
        $this->assertFalse($this->signatures->verify($oldEnvelope));
    }

    public function test_unknown_or_substituted_key_id_is_rejected(): void
    {
        $envelope = $this->signatures->sign(['plan' => 'pro']);

        $unknown = $envelope;
        $unknown['key_id'] = 'unknown-key';
        $this->assertFalse($this->signatures->verify($unknown));

        $substituted = $envelope;
        $substituted['key_id'] = 'market-v1';
        $this->assertFalse($this->signatures->verify($substituted));
    }

    public function test_missing_or_malformed_public_key_fails_verification_without_throwing(): void
    {
        $envelope = $this->signatures->sign(['plan' => 'best']);

        config()->set('tableplay.license_public_key', null);
        config()->set('tableplay.trusted_license_public_keys', []);
        $this->assertFalse($this->signatures->verify($envelope));

        config()->set('tableplay.license_public_key', 'not-valid-base64');
        config()->set('tableplay.trusted_license_public_keys', ['market-v2' => 'also-invalid']);
        $this->assertFalse($this->signatures->verify($envelope));
    }

    public function test_missing_or_malformed_private_key_cannot_sign(): void
    {
        config()->set('tableplay.license_private_key', null);

        $this->expectException(RuntimeException::class);
        $this->signatures->sign(['plan' => 'best']);
    }

    public function test_malformed_private_key_is_reported(): void
    {
        config()->set('tableplay.license_private_key', base64_encode('too-short'));

        $this->expectException(InvalidArgumentException::class);
        $this->signatures->sign(['plan' => 'best']);
    }
}
