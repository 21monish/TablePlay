<?php

namespace App\Services;

use App\Models\{LocalOfflineActivationRequest, RestaurantSubscription, TablePlayInstallation};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class OfflineActivationRequestService
{
    public const TYPE = 'tableplay-offline-activation-request';

    public function __construct(private LicenseSignatureService $signatures) {}

    public function create(TablePlayInstallation $installation, string $serverVersion): array
    {
        $this->assertSodiumAvailable();
        $fingerprint = $this->deviceFingerprint();

        return DB::transaction(function () use ($installation, $serverVersion, $fingerprint): array {
            $installation = TablePlayInstallation::query()->lockForUpdate()->findOrFail($installation->getKey());
            if ($this->hasProtectedLicenseIdentity($installation) && $installation->device_fingerprint
                && ! hash_equals($installation->device_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages([
                    'activation_request' => 'This installation was moved to another computer. Transfer the licence in TablePlay Cloud before creating a new request.',
                ]);
            }

            [$publicKey, $privateKey] = $this->installationKeyPair($installation);
            $pending = LocalOfflineActivationRequest::query()
                ->where('installation_uuid', $installation->installation_uuid)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($pending && $this->isReusable($pending, $installation, $fingerprint, $publicKey)) {
                return $pending->request_document;
            }

            $generatedAt = now();
            $payload = [
                'request_id' => (string) Str::uuid(),
                'installation_uuid' => $installation->installation_uuid,
                'device_name' => mb_substr(php_uname('n') ?: 'Restaurant server', 0, 255),
                'device_fingerprint' => $fingerprint,
                'request_public_key' => $publicKey,
                'server_version' => mb_substr($serverVersion, 0, 40),
                'generated_at' => $generatedAt->toIso8601String(),
            ];
            $envelope = [
                'version' => 1,
                'type' => self::TYPE,
                'algorithm' => 'Ed25519',
                'payload' => $payload,
                'signature' => base64_encode(sodium_crypto_sign_detached($this->signatures->canonicalJson($payload), base64_decode($privateKey, true))),
            ];
            $hash = $this->documentHash($envelope);
            $installation->update([
                'device_fingerprint' => $fingerprint,
                'offline_request_id' => $payload['request_id'],
                'offline_request_hash' => $hash,
                'offline_request_generated_at' => $payload['generated_at'],
            ]);

            LocalOfflineActivationRequest::where('installation_uuid', $installation->installation_uuid)
                ->where('status', 'pending')
                ->update(['status' => 'superseded']);
            LocalOfflineActivationRequest::create([
                'request_id' => $payload['request_id'],
                'installation_uuid' => $installation->installation_uuid,
                'request_hash' => $hash,
                'request_document' => $envelope,
                'status' => 'pending',
                'generated_at' => $payload['generated_at'],
                'expires_at' => $generatedAt->copy()->addDays(max(1, (int) config('tableplay.offline_request_ttl_days', 30))),
            ]);

            return $envelope;
        });
    }

    public function validate(array $envelope): array
    {
        $this->assertSodiumAvailable();
        if (($envelope['version'] ?? null) !== 1 || ($envelope['type'] ?? null) !== self::TYPE
            || ($envelope['algorithm'] ?? null) !== 'Ed25519' || ! is_array($envelope['payload'] ?? null)) {
            $this->invalid('The selected file is not a supported TablePlay activation request.');
        }
        $payload = $envelope['payload'];
        foreach (['request_id', 'installation_uuid', 'device_name', 'device_fingerprint', 'request_public_key', 'server_version', 'generated_at'] as $field) {
            if (! is_string($payload[$field] ?? null) || trim($payload[$field]) === '') $this->invalid("The activation request is missing {$field}.");
        }
        if (! Str::isUuid($payload['request_id']) || ! Str::isUuid($payload['installation_uuid'])) $this->invalid('The activation request contains an invalid identifier.');
        if (mb_strlen($payload['device_name']) > 255 || mb_strlen($payload['server_version']) > 40) $this->invalid('The activation request contains oversized device information.');
        if (! preg_match('/\A[a-f0-9]{64}\z/', $payload['device_fingerprint'])) $this->invalid('The activation request device fingerprint is invalid.');

        $publicKey = base64_decode($payload['request_public_key'], true);
        $signature = base64_decode((string) ($envelope['signature'] ?? ''), true);
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $this->signatures->canonicalJson($payload), $publicKey)) {
            $this->invalid('The activation request signature is invalid or the file was modified.');
        }
        try { $generatedAt = Carbon::parse($payload['generated_at']); }
        catch (\Throwable) { $this->invalid('The activation request date is invalid.'); }
        if ($generatedAt->greaterThan(now()->addMinutes(10))) $this->invalid('The activation request date is in the future. Check the restaurant computer clock.');
        if ($generatedAt->lessThan(now()->subDays(max(1, (int) config('tableplay.offline_request_ttl_days', 30))))) {
            $this->invalid('The activation request has expired. Export a new request from the restaurant server.');
        }
        $payload['request_hash'] = $this->documentHash($envelope);
        return $payload;
    }

    public function documentHash(array $envelope): string
    {
        return hash('sha256', $this->signatures->canonicalJson($envelope));
    }

    public function deviceFingerprint(): string
    {
        if (filled(config('tableplay.device_fingerprint_override'))) {
            return hash('sha256', (string) config('tableplay.device_fingerprint_override'));
        }
        $machineId = '';
        if (PHP_OS_FAMILY === 'Windows') {
            try {
                $process = new Process(['reg.exe', 'query', 'HKLM\SOFTWARE\Microsoft\Cryptography', '/v', 'MachineGuid']);
                $process->setTimeout(3)->run();
                if ($process->isSuccessful() && preg_match('/MachineGuid\s+REG_SZ\s+([^\r\n]+)/i', $process->getOutput(), $matches)) $machineId = trim($matches[1]);
            } catch (\Throwable) {}
        } else {
            foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $path) {
                if (is_readable($path) && trim((string) file_get_contents($path)) !== '') { $machineId = trim((string) file_get_contents($path)); break; }
            }
        }
        if ($machineId !== '') {
            return hash('sha256', strtolower(PHP_OS_FAMILY).'|machine-id|'.strtolower($machineId));
        }

        return hash('sha256', implode('|', [PHP_OS_FAMILY, php_uname('n'), 'machine-id-unavailable', realpath(base_path()) ?: base_path()]));
    }

    private function isReusable(
        LocalOfflineActivationRequest $request,
        TablePlayInstallation $installation,
        string $fingerprint,
        string $publicKey,
    ): bool {
        $document = $request->request_document;
        if (! is_array($document) || ! hash_equals((string) $request->request_hash, $this->documentHash($document))) {
            return false;
        }

        try {
            $payload = $this->validate($document);
        } catch (ValidationException) {
            return false;
        }

        return hash_equals((string) $request->request_id, (string) ($payload['request_id'] ?? ''))
            && hash_equals($installation->installation_uuid, (string) ($payload['installation_uuid'] ?? ''))
            && hash_equals($fingerprint, (string) ($payload['device_fingerprint'] ?? ''))
            && hash_equals($publicKey, (string) ($payload['request_public_key'] ?? ''));
    }

    private function installationKeyPair(TablePlayInstallation $installation): array
    {
        $storedPublic = (string) $installation->offline_request_public_key;
        $storedPrivate = (string) $installation->offline_request_private_key;
        $public = base64_decode($storedPublic, true);
        $private = base64_decode($storedPrivate, true);
        if ($public !== false && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && $private !== false && strlen($private) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            && hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($private))) {
            return [$storedPublic, $storedPrivate];
        }
        if ($this->hasProtectedLicenseIdentity($installation)) {
            throw ValidationException::withMessages(['activation_request' => 'The local offline activation identity is damaged. Contact TablePlay support for a licence transfer.']);
        }
        $pair = sodium_crypto_sign_keypair();
        $storedPublic = base64_encode(sodium_crypto_sign_publickey($pair));
        $storedPrivate = base64_encode(sodium_crypto_sign_secretkey($pair));
        $installation->update(['offline_request_public_key' => $storedPublic, 'offline_request_private_key' => $storedPrivate]);
        return [$storedPublic, $storedPrivate];
    }

    private function assertSodiumAvailable(): void
    {
        if (! extension_loaded('sodium') || ! function_exists('sodium_crypto_sign_detached')) {
            throw ValidationException::withMessages([
                'activation_request' => 'The PHP Sodium extension is required for secure offline activation. Repair the TablePlay server installation.',
            ]);
        }
    }

    private function hasProtectedLicenseIdentity(TablePlayInstallation $installation): bool
    {
        if ((int) $installation->license_revision > 0) return true;
        if (! $installation->license_reference) return false;

        $subscription = RestaurantSubscription::where('license_reference', $installation->license_reference)->first();
        return ! $subscription || ! in_array($subscription->source ?? 'legacy', ['legacy', 'local'], true);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['activation_request' => $message]);
    }
}
