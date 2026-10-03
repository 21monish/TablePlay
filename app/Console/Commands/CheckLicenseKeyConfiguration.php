<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckLicenseKeyConfiguration extends Command
{
    protected $signature = 'tableplay:license-check';

    protected $description = 'Validate the TablePlay Cloud Ed25519 keypair without displaying secret key material.';

    public function handle(): int
    {
        $keyId = trim((string) config('tableplay.license_key_id', ''));
        $encodedPublic = trim((string) config('tableplay.license_public_key', ''));
        $encodedPrivate = trim((string) config('tableplay.license_private_key', ''));
        if (! extension_loaded('sodium')) {
            $this->components->error('Sodium is not loaded. Ed25519 signing and verification cannot run.');

            return self::FAILURE;
        }

        $public = base64_decode($encodedPublic, true);
        $private = base64_decode($encodedPrivate, true);
        $publicValid = is_string($public) && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
        $privateValid = is_string($private) && strlen($private) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;
        $pairMatches = $publicValid
            && $privateValid
            && hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($private));
        $selfTest = false;
        if ($pairMatches) {
            $probe = random_bytes(32);
            $signature = sodium_crypto_sign_detached($probe, $private);
            $selfTest = sodium_crypto_sign_verify_detached($signature, $probe, $public);
        }
        $fingerprint = $publicValid ? hash('sha256', $public) : 'unavailable';

        $this->table(['Check', 'Result'], [
            ['Sodium extension', 'ready'],
            ['Key ID', $keyId !== '' ? $keyId : 'missing'],
            ['Public key', $publicValid ? 'valid Ed25519 public key' : 'missing or invalid'],
            ['Private key', $privateValid ? 'configured (hidden)' : 'missing or invalid'],
            ['Keypair match', $pairMatches ? 'yes' : 'no'],
            ['Sign/verify self-test', $selfTest ? 'passed' : 'failed'],
            ['Public fingerprint (SHA-256)', $fingerprint],
        ]);
        if (is_string($private)) sodium_memzero($private);

        if ($keyId === '' || ! $publicValid || ! $privateValid || ! $pairMatches || ! $selfTest) {
            $this->components->error('TablePlay Cloud signing is not ready. No private key material was displayed.');

            return self::FAILURE;
        }
        $this->components->info('TablePlay Cloud signing is ready. No private key material was displayed.');

        return self::SUCCESS;
    }
}
