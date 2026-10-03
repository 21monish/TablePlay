<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class LicenseSignatureService
{
    public function sign(array $payload): array
    {
        $this->assertSodiumAvailable();
        $keyId = trim((string) config('tableplay.license_key_id'));
        if ($keyId === '' || mb_strlen($keyId) > 100) throw new RuntimeException('The TablePlay licence key ID is not configured correctly.');
        $privateKey = $this->decodeKey((string) config('tableplay.license_private_key'), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'private');
        $publicKey = $this->decodeKey((string) config('tableplay.license_public_key'), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public');
        if (! hash_equals($publicKey, sodium_crypto_sign_publickey_from_secretkey($privateKey))) {
            throw new RuntimeException('The TablePlay licence signing keys do not form a valid keypair.');
        }
        $canonical = $this->canonicalJson($payload);

        return [
            'version' => 1,
            'algorithm' => 'Ed25519',
            'key_id' => $keyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached($canonical, $privateKey)),
        ];
    }

    public function verify(array $envelope): bool
    {
        if (($envelope['version'] ?? null) !== 1 || ($envelope['algorithm'] ?? null) !== 'Ed25519'
            || ! is_array($envelope['payload'] ?? null) || ! is_string($envelope['key_id'] ?? null)
            || trim($envelope['key_id']) === '') return false;
        try {
            $this->assertSodiumAvailable();
            $encodedKey = $this->verificationKey(trim($envelope['key_id']));
            if ($encodedKey === null) return false;
            $publicKey = $this->decodeKey($encodedKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public');
            $signature = base64_decode((string) ($envelope['signature'] ?? ''), true);
            if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) return false;
            return sodium_crypto_sign_verify_detached($signature, $this->canonicalJson($envelope['payload']), $publicKey);
        } catch (\Throwable) {
            return false;
        }
    }

    public function signingReady(): bool
    {
        try {
            $this->assertSodiumAvailable();
            $private = $this->decodeKey((string) config('tableplay.license_private_key'), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'private');
            $public = $this->decodeKey((string) config('tableplay.license_public_key'), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public');
            return filled(config('tableplay.license_key_id'))
                && hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($private));
        } catch (\Throwable) {
            return false;
        }
    }

    public function canonicalJson(array $payload): string
    {
        return json_encode($this->canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function generateKeyPair(): array
    {
        $this->assertSodiumAvailable();
        $pair = sodium_crypto_sign_keypair();
        return ['public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'private_key' => base64_encode(sodium_crypto_sign_secretkey($pair))];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn ($item) => $this->canonicalize($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function decodeKey(string $encoded, int $expectedLength, string $name): string
    {
        if ($encoded === '') throw new RuntimeException("The TablePlay licence {$name} key is not configured.");
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) !== $expectedLength) throw new InvalidArgumentException("The TablePlay licence {$name} key is invalid.");
        return $decoded;
    }

    private function verificationKey(string $keyId): ?string
    {
        $trusted = config('tableplay.trusted_license_public_keys', []);
        if (is_array($trusted) && is_string($trusted[$keyId] ?? null) && trim($trusted[$keyId]) !== '') {
            return trim($trusted[$keyId]);
        }
        if (hash_equals((string) config('tableplay.license_key_id'), $keyId)) {
            $current = trim((string) config('tableplay.license_public_key'));
            return $current !== '' ? $current : null;
        }
        return null;
    }

    private function assertSodiumAvailable(): void
    {
        if (! extension_loaded('sodium') || ! function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('The PHP Sodium extension is required for TablePlay licence signing and verification.');
        }
    }
}
