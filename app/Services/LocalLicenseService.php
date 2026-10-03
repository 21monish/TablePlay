<?php

namespace App\Services;

use App\Models\{CommercialPlan, LicenseSyncLog, LocalOfflineActivationRequest, RestaurantSubscription, TablePlayInstallation};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocalLicenseService
{
    public function __construct(
        private LicenseSignatureService $signatures,
        private OfflineActivationRequestService $offlineRequests,
    ) {}

    public function installation(): TablePlayInstallation
    {
        $fingerprint = $this->offlineRequests->deviceFingerprint();
        $installation = TablePlayInstallation::firstOrCreate(['id' => 1], [
            'installation_uuid' => (string) Str::uuid(),
            'cloud_url' => config('tableplay.cloud_url') ?: null,
            'device_fingerprint' => $fingerprint,
        ]);
        if (! $installation->device_fingerprint
            || (! $installation->license_reference && ! hash_equals((string) $installation->device_fingerprint, $fingerprint))) {
            $installation->update(['device_fingerprint' => $fingerprint]);
        }
        return $installation->fresh();
    }

    public function activate(string $licenseKey, ?string $cloudUrl = null): RestaurantSubscription
    {
        $installation = $this->installation();
        $url = rtrim($cloudUrl ?: $installation->cloud_url ?: (string) config('tableplay.cloud_url'), '/');
        if ($url === '') throw ValidationException::withMessages(['cloud_url' => 'Enter the TablePlay Cloud address before activation.']);
        $response = Http::acceptJson()->timeout((int) config('tableplay.sync_timeout_seconds', 12))->post($url.'/api/cloud/v1/licenses/activate', [
            'license_key' => $licenseKey, 'installation_uuid' => $installation->installation_uuid,
            'device_name' => php_uname('n') ?: 'Restaurant server', 'device_fingerprint' => $installation->device_fingerprint,
            'server_version' => $this->serverVersion(),
        ]);
        if (! $response->successful()) throw ValidationException::withMessages(['license_key' => $response->json('message') ?: 'TablePlay Cloud rejected this activation.']);
        $subscription = $this->installEnvelope((array) $response->json('license'), 'cloud');
        $installation->update(['cloud_url' => $url, 'activation_token' => $response->json('installation_token'), 'license_reference' => $subscription->license_reference, 'last_sync_at' => now(), 'last_sync_error' => null]);
        return $subscription;
    }

    public function sync(): RestaurantSubscription
    {
        $installation = $this->installation();
        if (! $installation->cloud_url || ! $installation->activation_token) throw new RuntimeException('This installation has not been activated online.');
        try {
            $response = Http::acceptJson()->withToken($installation->activation_token)->timeout((int) config('tableplay.sync_timeout_seconds', 12))
                ->post(rtrim($installation->cloud_url, '/').'/api/cloud/v1/licenses/sync', [
                    'installation_uuid' => $installation->installation_uuid,
                    'device_fingerprint' => $installation->device_fingerprint,
                    'server_version' => $this->serverVersion(),
                ]);
            if (! $response->successful()) throw new RuntimeException($response->json('message') ?: 'TablePlay Cloud synchronization failed.');
            $subscription = $this->installEnvelope((array) $response->json('license'), 'cloud');
            $installation->update(['last_sync_at' => now(), 'last_sync_error' => null, 'license_reference' => $subscription->license_reference]);
            $this->log($installation->installation_uuid, 'local.sync', 'success');
            return $subscription;
        } catch (\Throwable $error) {
            $installation->update(['last_sync_error' => $error->getMessage()]);
            $this->log($installation->installation_uuid, 'local.sync', 'failed', $error->getMessage());
            throw $error;
        }
    }

    public function import(string $json): RestaurantSubscription
    {
        try { $envelope = json_decode($json, true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw ValidationException::withMessages(['license_file' => 'The selected file is not a valid TablePlay licence.']); }
        if (! is_array($envelope)) throw ValidationException::withMessages(['license_file' => 'The selected file is not a valid TablePlay licence.']);
        return $this->installEnvelope($envelope, 'offline');
    }

    public function installEnvelope(array $envelope, string $source): RestaurantSubscription
    {
        if (! in_array($source, ['cloud', 'offline'], true)) {
            $this->invalid('The licence installation source is not supported.');
        }
        if (! $this->signatures->verify($envelope)) {
            $this->invalid('The licence signature is invalid or was signed by an unknown TablePlay key.');
        }

        $payload = $this->validatePayload($envelope['payload'] ?? null);
        if ($source === 'offline' && ! $payload['_offline_verification_due_at']) {
            // Accept trusted v1 offline files that used expires_at as the lease.
            $payload['_offline_verification_due_at'] = $payload['_expires_at'];
        }
        if ($source === 'cloud' && ($payload['activation_method'] ?? null) !== 'online') {
            $this->invalid('The online licence provenance is invalid. Request a new licence from TablePlay Cloud.');
        }
        $installation = $this->installation();
        $currentFingerprint = $this->offlineRequests->deviceFingerprint();
        if (! hash_equals($installation->installation_uuid, $payload['installation_uuid'])) {
            $this->invalid('This licence belongs to a different TablePlay server installation.');
        }
        if (! hash_equals($currentFingerprint, $payload['device_fingerprint'])) {
            $this->invalid('This licence belongs to a different computer. Generate a new activation request on this server.');
        }
        if ($installation->device_fingerprint
            && ! hash_equals((string) $installation->device_fingerprint, $payload['device_fingerprint'])) {
            $this->invalid('The licence device identity does not match this TablePlay installation.');
        }

        $offlineRequest = $source === 'offline'
            ? $this->validateOfflineBinding($payload, $installation)
            : null;
        $plan = CommercialPlan::where('slug', $payload['plan'])->first();
        if (! $plan) {
            $this->invalid("The licence references an unknown plan ({$payload['plan']}). Update TablePlay before importing it.");
        }

        $signedPayload = $this->withoutParsedDates($payload);
        $contentPayload = $signedPayload;
        unset($contentPayload['issued_at']);
        $contentHash = hash('sha256', $this->signatures->canonicalJson($contentPayload));
        $storedFeatures = json_decode($this->signatures->canonicalJson($payload['features']), true, flags: JSON_THROW_ON_ERROR);
        $storedLimits = json_decode($this->signatures->canonicalJson($payload['limits']), true, flags: JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($payload, $signedPayload, $envelope, $source, $plan, $installation, $offlineRequest, $contentHash, $storedFeatures, $storedLimits) {
            $locked = TablePlayInstallation::lockForUpdate()->findOrFail($installation->id);
            $currentRevision = (int) $locked->license_revision;
            if ($payload['license_revision'] < $currentRevision) {
                $this->invalid('This licence file is older than the licence already installed. Export a current licence from TablePlay Cloud.');
            }
            if ($payload['license_revision'] === $currentRevision && $currentRevision > 0
                && $locked->license_content_hash
                && ! hash_equals((string) $locked->license_content_hash, $contentHash)) {
                $this->invalid('This licence has the same revision as the installed licence but different contents. It was not applied.');
            }
            if (RestaurantSubscription::where('license_uuid', $payload['license_uuid'])
                ->where('license_reference', '!=', $payload['license_reference'])->exists()) {
                $this->invalid('This licence identifier was already used by another subscription.');
            }

            RestaurantSubscription::where('license_reference', '!=', $payload['license_reference'])->update(['status' => 'expired']);
            $subscription = RestaurantSubscription::updateOrCreate(['license_reference' => $payload['license_reference']], [
                'commercial_plan_id' => $plan->id, 'installation_uuid' => $locked->installation_uuid,
                'license_uuid' => $payload['license_uuid'], 'license_revision' => $payload['license_revision'],
                'status' => $payload['status'], 'source' => $source,
                'starts_at' => $payload['_starts_at'], 'expires_at' => $payload['_expires_at'],
                'subscription_expires_at' => $payload['_subscription_expires_at'],
                'offline_verification_due_at' => $payload['_offline_verification_due_at'],
                'grace_ends_at' => $payload['_grace_ends_at'],
                'entitlement_snapshot' => $storedFeatures, 'entitlement_limits' => $storedLimits,
                'signed_payload' => $this->signatures->canonicalJson($signedPayload),
                'signature' => $envelope['signature'], 'license_key_id' => $envelope['key_id'], 'last_verified_at' => now(), 'verification_error' => null,
            ]);

            $locked->update([
                'license_reference' => $subscription->license_reference,
                'device_fingerprint' => $payload['device_fingerprint'],
                'license_revision' => $payload['license_revision'],
                'license_content_hash' => $contentHash,
                'last_license_issued_at' => $payload['_issued_at'],
            ]);
            if ($offlineRequest) {
                $offlineRequest->update([
                    'status' => 'fulfilled',
                    'fulfilled_at' => now(),
                    'fulfilled_revision' => $payload['license_revision'],
                ]);
            }
            $this->log($locked->installation_uuid, 'local.license_import', 'success');
            return $subscription->fresh('plan');
        });
    }

    public function serverVersion(): string
    {
        $manifest = base_path('../payload-manifest.json');
        if (is_file($manifest)) return (string) data_get(json_decode(file_get_contents($manifest), true), 'installer_version', 'development');
        return 'development';
    }

    private function validatePayload(mixed $raw): array
    {
        if (! is_array($raw)) $this->invalid('The signed licence payload is missing.');
        foreach (['license_uuid', 'license_reference', 'installation_uuid', 'device_fingerprint', 'plan', 'status', 'issued_at', 'starts_at'] as $field) {
            if (! is_string($raw[$field] ?? null) || trim($raw[$field]) === '') {
                $this->invalid("The signed licence is missing {$field}.");
            }
        }
        if (! Str::isUuid($raw['license_uuid']) || ! Str::isUuid($raw['installation_uuid'])) {
            $this->invalid('The signed licence contains an invalid identifier.');
        }
        if (mb_strlen($raw['license_reference']) > 100 || mb_strlen($raw['plan']) > 100) {
            $this->invalid('The signed licence contains an oversized reference or plan name.');
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/', $raw['device_fingerprint'])) {
            $this->invalid('The signed licence device fingerprint is invalid.');
        }
        if (! in_array($raw['status'], ['trial', 'active', 'grace', 'suspended', 'expired'], true)) {
            $this->invalid('The signed licence status is not supported.');
        }

        $revision = $raw['license_revision'] ?? null;
        if (! is_int($revision) || $revision < 1) $this->invalid('The signed licence revision is invalid.');
        if (! is_array($raw['features'] ?? null) || array_is_list($raw['features'])) {
            $this->invalid('The signed licence feature list is invalid.');
        }
        foreach ($raw['features'] as $feature => $enabled) {
            if (! is_string($feature) || ! is_bool($enabled)) $this->invalid('The signed licence contains an invalid feature value.');
        }

        $limits = $raw['limits'] ?? ['paired_tables' => $raw['max_paired_tables'] ?? null];
        if (! is_array($limits) || array_is_list($limits)) $this->invalid('The signed licence limits are invalid.');
        $pairedTables = $limits['paired_tables'] ?? null;
        if ($pairedTables !== null && (! is_int($pairedTables) || $pairedTables < 0)) {
            $this->invalid('The signed licence table limit is invalid.');
        }
        $limits['paired_tables'] = $pairedTables;

        $issuedAt = $this->parseDate($raw['issued_at'], 'issued_at');
        $startsAt = $this->parseDate($raw['starts_at'], 'starts_at');
        $expiresAt = filled($raw['expires_at'] ?? null) ? $this->parseDate($raw['expires_at'], 'expires_at') : null;
        $subscriptionExpiresAt = filled($raw['subscription_expires_at'] ?? null)
            ? $this->parseDate($raw['subscription_expires_at'], 'subscription_expires_at')
            : $expiresAt;
        $offlineDueValue = $raw['offline_verification_due_at'] ?? $raw['offline_refresh_due_at'] ?? null;
        $offlineVerificationDueAt = filled($offlineDueValue)
            ? $this->parseDate($offlineDueValue, 'offline_verification_due_at')
            : null;
        $graceEndsAt = filled($raw['grace_ends_at'] ?? null) ? $this->parseDate($raw['grace_ends_at'], 'grace_ends_at') : null;
        if ($issuedAt->greaterThan(now()->addMinutes(10))) $this->invalid('The licence issue date is in the future. Check this computer clock.');
        if ($subscriptionExpiresAt && $subscriptionExpiresAt->lessThan($startsAt)) $this->invalid('The licence expiry date is before its start date.');
        if ($graceEndsAt && (! $subscriptionExpiresAt || $graceEndsAt->lessThan($subscriptionExpiresAt))) {
            $this->invalid('The licence grace-period date is invalid.');
        }

        $raw['license_revision'] = $revision;
        $raw['limits'] = $limits;
        $raw['_issued_at'] = $issuedAt;
        $raw['_starts_at'] = $startsAt;
        $raw['_expires_at'] = $expiresAt;
        $raw['_subscription_expires_at'] = $subscriptionExpiresAt;
        $raw['_offline_verification_due_at'] = $offlineVerificationDueAt;
        $raw['_grace_ends_at'] = $graceEndsAt;
        return $raw;
    }

    private function validateOfflineBinding(array $payload, TablePlayInstallation $installation): LocalOfflineActivationRequest
    {
        foreach (['offline_request_id', 'offline_request_hash', 'request_public_key'] as $field) {
            if (! is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
                $this->invalid("The offline licence is missing {$field}.");
            }
        }
        if (($payload['activation_method'] ?? null) !== 'offline' || ! Str::isUuid($payload['offline_request_id'])
            || ! preg_match('/\A[a-f0-9]{64}\z/', $payload['offline_request_hash'])) {
            $this->invalid('The offline licence request binding is invalid.');
        }

        $request = LocalOfflineActivationRequest::where('request_id', $payload['offline_request_id'])->first();
        if (! $request || ! in_array($request->status, ['pending', 'fulfilled'], true)) {
            $this->invalid('The matching activation request was not created by this TablePlay server.');
        }
        if ($request->status === 'pending' && $request->expires_at->isPast()) {
            $this->invalid('The matching activation request has expired. Export a new request.');
        }
        if (! hash_equals($request->installation_uuid, $installation->installation_uuid)
            || ! hash_equals($request->request_hash, $payload['offline_request_hash'])) {
            $this->invalid('The licence does not match the activation request exported by this server.');
        }
        if (! hash_equals((string) $installation->offline_request_public_key, $payload['request_public_key'])) {
            $this->invalid('The licence does not match this server activation identity.');
        }
        $requestPayload = data_get($request->request_document, 'payload', []);
        if (! is_array($requestPayload)
            || ! hash_equals((string) ($requestPayload['request_public_key'] ?? ''), $payload['request_public_key'])
            || ! hash_equals((string) ($requestPayload['device_fingerprint'] ?? ''), $payload['device_fingerprint'])) {
            $this->invalid('The stored activation request does not match this licence.');
        }
        return $request;
    }

    private function parseDate(string $value, string $field): Carbon
    {
        try { return Carbon::parse($value); }
        catch (\Throwable) { $this->invalid("The signed licence contains an invalid {$field} date."); }
    }

    private function withoutParsedDates(array $payload): array
    {
        unset($payload['_issued_at'], $payload['_starts_at'], $payload['_expires_at'], $payload['_subscription_expires_at'], $payload['_offline_verification_due_at'], $payload['_grace_ends_at']);
        return $payload;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['license' => $message]);
    }

    private function log(string $uuid, string $event, string $status, ?string $error = null): void { LicenseSyncLog::create(['installation_uuid' => $uuid, 'event' => $event, 'status' => $status, 'error' => $error]); }
}
