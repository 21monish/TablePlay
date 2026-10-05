<?php

namespace App\Services;

use App\Models\{CloudInstallation, CloudLicenseKey, CloudOfflineActivationRequest, CloudSubscription, LicenseSyncLog};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class CloudLicenseService
{
    public function __construct(
        private LicenseSignatureService $signatures,
        private OfflineActivationRequestService $offlineRequests,
    ) {}

    public function issueKey(CloudSubscription $subscription): string
    {
        $plain = 'TP-'.implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 8));
        CloudLicenseKey::create(['cloud_subscription_id' => $subscription->id, 'key_prefix' => substr($plain, 0, 11), 'key_hash' => hash('sha256', $this->normalizeKey($plain)), 'is_active' => true, 'expires_at' => $subscription->grace_ends_at]);
        return $plain;
    }

    public function activate(array $data, ?string $ipAddress = null): array
    {
        $this->assertSigningReady('license_key');

        return DB::transaction(function () use ($data, $ipAddress) {
            $key = CloudLicenseKey::query()
                ->where('key_hash', hash('sha256', $this->normalizeKey($data['license_key'])))
                ->lockForUpdate()
                ->first();
            if (! $key || ! $key->is_active || ($key->expires_at && $key->expires_at->isPast())) {
                $this->invalid('license_key', 'This TablePlay licence key is invalid, has already been used, or has expired.');
            }

            $subscription = CloudSubscription::with('restaurant', 'plan')->lockForUpdate()->findOrFail($key->cloud_subscription_id);
            if ($subscription->restaurant->status !== 'active') $this->invalid('license_key', 'This restaurant account is not active.');
            if (in_array($this->status($subscription), ['suspended', 'expired'], true)) {
                $this->invalid('license_key', 'This subscription cannot activate a server.');
            }

            $installation = CloudInstallation::where('installation_uuid', $data['installation_uuid'])->lockForUpdate()->first();
            if ($installation && $installation->cloud_subscription_id !== $subscription->id) {
                $this->invalid('installation_uuid', 'This installation is registered to another restaurant.');
            }
            if ($installation && $installation->status !== 'active') {
                $this->invalid('installation_uuid', 'This server was deactivated or transferred. Create an activation request on the replacement server.');
            }
            $incomingFingerprint = filled($data['device_fingerprint'] ?? null) ? (string) $data['device_fingerprint'] : null;
            if (! $incomingFingerprint || ! preg_match('/\A[a-f0-9]{64}\z/', $incomingFingerprint)) {
                $this->invalid('device_fingerprint', 'A valid server device fingerprint is required for activation.');
            }
            if ($installation?->device_fingerprint && $incomingFingerprint
                && ! hash_equals((string) $installation->device_fingerprint, $incomingFingerprint)) {
                $this->invalid('device_fingerprint', 'This installation identifier is already bound to another computer. Use the licence transfer workflow.');
            }
            // The subscription row is already locked, serializing capacity
            // checks across online and offline activation. PostgreSQL cannot
            // apply FOR UPDATE to an aggregate count.
            if (! $installation && $subscription->max_installations !== null
                && $subscription->installations()->where('status', 'active')->count() >= $subscription->max_installations) {
                $this->invalid('license_key', 'This licence has reached its active server limit. Deactivate or transfer the old installation first.');
            }

            $token = Str::random(80);
            $values = [
                'cloud_subscription_id' => $subscription->id,
                'device_name' => $data['device_name'],
                'device_fingerprint' => $installation?->device_fingerprint ?: $incomingFingerprint,
                'activation_token_hash' => hash('sha256', $token),
                'activation_method' => 'online',
                'status' => 'active',
                'server_version' => $data['server_version'] ?? null,
                'last_ip_address' => $ipAddress,
                'activated_at' => $installation?->activated_at ?: now(),
                'last_seen_at' => now(),
                'deactivated_at' => null,
            ];
            if ($installation) $installation->update($values);
            else $installation = CloudInstallation::create(['installation_uuid' => $data['installation_uuid'], ...$values]);

            $installation->update(['license_revision' => max(1, (int) $subscription->license_revision)]);
            $license = $this->envelope($installation->fresh('subscription.restaurant', 'subscription.plan'));
            $key->update(['is_active' => false, 'last_used_at' => now()]);
            $this->log($installation->installation_uuid, 'cloud.activation', 'success', [
                'plan' => $subscription->plan->slug,
                'activation_method' => 'online',
            ]);

            return ['installation_token' => $token, 'license' => $license];
        });
    }

    public function sync(string $installationUuid, string $token, array $telemetry = [], ?string $ipAddress = null): array
    {
        $installation = CloudInstallation::with('subscription.restaurant', 'subscription.plan')->where('installation_uuid', $installationUuid)->where('activation_token_hash', hash('sha256', $token))->first();
        abort_unless($installation && $installation->status === 'active', 401, 'Installation authentication failed.');
        if (filled($telemetry['device_fingerprint'] ?? null)
            && ! hash_equals((string) $installation->device_fingerprint, (string) $telemetry['device_fingerprint'])) {
            abort(401, 'This installation token is bound to a different computer.');
        }
        $installation->update(['last_seen_at' => now(), 'last_ip_address' => $ipAddress, 'server_version' => $telemetry['server_version'] ?? $installation->server_version]);
        $installation->update(['license_revision' => max((int) $installation->license_revision, (int) $installation->subscription->license_revision)]);
        $this->log($installationUuid, 'cloud.sync', 'success', ['server_version' => $installation->server_version]);
        return ['license' => $this->envelope($installation->fresh('subscription.restaurant', 'subscription.plan')), 'synced_at' => now()->toIso8601String()];
    }

    public function importOfflineRequest(string $json): CloudOfflineActivationRequest
    {
        try {
            $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->invalid('activation_request', 'The selected file is not valid JSON. Export a new activation request from the restaurant server.');
        }
        if (! is_array($document)) {
            $this->invalid('activation_request', 'The selected file is not a valid TablePlay activation request.');
        }

        $payload = $this->offlineRequests->validate($document);
        $requestHash = $payload['request_hash'];
        unset($payload['request_hash']);

        $existing = CloudOfflineActivationRequest::where('request_id', $payload['request_id'])->first();
        if ($existing) {
            if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                $this->invalid('activation_request', 'This request identifier was already imported with different contents. Export a new request.');
            }

            return $existing;
        }
        if (CloudOfflineActivationRequest::where('request_hash', $requestHash)->exists()) {
            $this->invalid('activation_request', 'This activation request has already been imported under another identifier.');
        }

        $installation = CloudInstallation::where('installation_uuid', $payload['installation_uuid'])->first();
        $this->assertRequestMatchesInstallation($payload, $installation);
        $generatedAt = Carbon::parse($payload['generated_at']);

        $request = CloudOfflineActivationRequest::create([
            'request_id' => $payload['request_id'],
            'installation_uuid' => $payload['installation_uuid'],
            'cloud_installation_id' => $installation?->id,
            'device_name' => $payload['device_name'],
            'device_fingerprint' => $payload['device_fingerprint'],
            'request_public_key' => $payload['request_public_key'],
            'server_version' => $payload['server_version'],
            'request_hash' => $requestHash,
            'request_payload' => $payload,
            'status' => 'imported',
            'generated_at' => $generatedAt,
            'expires_at' => $generatedAt->copy()->addDays(max(1, (int) config('tableplay.offline_request_ttl_days', 30))),
            'imported_at' => now(),
        ]);
        $this->log($request->installation_uuid, 'cloud.offline_request_imported', 'success', [
            'request_id' => $request->request_id,
            'server_version' => $request->server_version,
        ]);

        return $request;
    }

    public function issueOfflineLicense(
        CloudOfflineActivationRequest $offlineRequest,
        CloudSubscription $selectedSubscription,
        ?int $issuedBy = null,
    ): CloudOfflineActivationRequest {
        $this->assertSigningReady('offline_activation');

        return DB::transaction(function () use ($offlineRequest, $selectedSubscription, $issuedBy) {
            $request = CloudOfflineActivationRequest::lockForUpdate()->findOrFail($offlineRequest->id);
            $subscription = CloudSubscription::with('restaurant', 'plan')->lockForUpdate()->findOrFail($selectedSubscription->id);

            if ($request->status === 'issued') {
                if ($request->cloud_subscription_id !== $subscription->id || ! is_array($request->license_envelope)) {
                    $this->invalid('offline_activation', 'This activation request has already been issued for another subscription.');
                }

                return $request;
            }
            if ($request->status !== 'imported') {
                $this->invalid('offline_activation', 'This activation request is no longer available for licensing.');
            }
            if ($request->expires_at->isPast()) {
                $this->invalid('offline_activation', 'This activation request has expired. Ask the restaurant to export a new request.');
            }
            if ($subscription->restaurant->status !== 'active') {
                $this->invalid('subscription_id', 'The selected restaurant account is not active.');
            }
            if (in_array($this->status($subscription), ['suspended', 'expired'], true)) {
                $this->invalid('subscription_id', 'The selected subscription is suspended or expired. Renew it before issuing a licence.');
            }

            $payload = (array) $request->request_payload;
            $installation = CloudInstallation::where('installation_uuid', $request->installation_uuid)->lockForUpdate()->first();
            if ($installation && $installation->cloud_subscription_id !== $subscription->id) {
                $this->invalid('subscription_id', 'This server installation is already registered to another restaurant.');
            }
            $this->assertRequestMatchesInstallation($payload, $installation);
            // Both activation paths hold the subscription row lock before
            // counting, so the aggregate does not need a row-lock clause.
            if (! $installation && $subscription->max_installations !== null
                && $subscription->installations()->where('status', 'active')->count() >= $subscription->max_installations) {
                $this->invalid('subscription_id', 'This subscription has reached its active server limit. Deactivate or transfer the old installation first.');
            }

            if (! $installation) {
                $installation = CloudInstallation::create([
                    'cloud_subscription_id' => $subscription->id,
                    'installation_uuid' => $request->installation_uuid,
                    'device_name' => $request->device_name,
                    'device_fingerprint' => $request->device_fingerprint,
                    'activation_token_hash' => hash('sha256', random_bytes(64)),
                    'activation_method' => 'offline',
                    'offline_request_id' => $request->request_id,
                    'offline_request_hash' => $request->request_hash,
                    'request_public_key' => $request->request_public_key,
                    'request_generated_at' => $request->generated_at,
                    'status' => 'active',
                    'server_version' => $request->server_version,
                    'activated_at' => now(),
                ]);
            } else {
                $installation->update([
                    'device_name' => $request->device_name,
                    'device_fingerprint' => $installation->device_fingerprint ?: $request->device_fingerprint,
                    'offline_request_id' => $request->request_id,
                    'offline_request_hash' => $request->request_hash,
                    'request_public_key' => $installation->request_public_key ?: $request->request_public_key,
                    'request_generated_at' => $request->generated_at,
                    'server_version' => $request->server_version,
                ]);
            }

            $revision = max((int) $subscription->license_revision, ((int) $installation->license_revision) + 1);
            $licenseUuid = (string) Str::uuid();
            $requestPublicKey = base64_decode((string) $request->request_public_key, true);
            $refreshDueAt = now()->addDays(max(1, (int) config('tableplay.offline_license_lease_days', 30)));
            $envelope = $this->envelope($installation->fresh('subscription.restaurant', 'subscription.plan'), [
                'license_uuid' => $licenseUuid,
                'license_revision' => $revision,
                'activation_method' => 'offline',
                'offline_request_id' => $request->request_id,
                'offline_request_hash' => $request->request_hash,
                'device_fingerprint' => $request->device_fingerprint,
                'request_public_key' => $request->request_public_key,
                'request_public_key_hash' => hash('sha256', $requestPublicKey),
                // Commercial expiry and the renewable offline verification
                // lease are deliberately separate. The restaurant can now see
                // whether it needs to renew its plan or merely refresh proof.
                'subscription_expires_at' => $subscription->expires_at?->toIso8601String(),
                'offline_verification_due_at' => $refreshDueAt->toIso8601String(),
                'offline_refresh_due_at' => $refreshDueAt->toIso8601String(),
            ]);
            $licenseHash = hash('sha256', $this->signatures->canonicalJson($envelope));

            $installation->update(['license_revision' => $revision]);
            $subscription->update(['license_revision' => $revision]);
            $request->update([
                'cloud_subscription_id' => $subscription->id,
                'cloud_installation_id' => $installation->id,
                'status' => 'issued',
                'issued_at' => now(),
                'license_revision' => $revision,
                'license_hash' => $licenseHash,
                'license_envelope' => $envelope,
                'issued_by' => $issuedBy,
            ]);
            $this->log($request->installation_uuid, 'cloud.offline_license_issued', 'success', [
                'request_id' => $request->request_id,
                'license_uuid' => $licenseUuid,
                'license_revision' => $revision,
                'plan' => $subscription->plan->slug,
            ]);

            return $request->fresh(['subscription.restaurant', 'subscription.plan', 'installation']);
        });
    }

    public function envelope(CloudInstallation $installation, array $additionalClaims = []): array
    {
        $subscription = $installation->subscription;
        if (! $subscription || ! $subscription->restaurant || ! $subscription->plan) {
            $this->invalid('license', 'The installation subscription is incomplete and cannot be licensed.');
        }
        if (! is_string($installation->device_fingerprint)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $installation->device_fingerprint)) {
            $this->invalid('license', 'The installation does not have a valid device fingerprint.');
        }
        $revision = max(1, (int) $subscription->license_revision, (int) $installation->license_revision);
        $licenseUuid = (string) Uuid::uuid5(
            '8ef0d9f4-1979-4bf7-a9ab-679d165d16bd',
            $subscription->license_reference.'|'.$installation->installation_uuid.'|'.$revision,
        );
        $payload = [
            'license_uuid' => $licenseUuid, 'license_revision' => $revision,
            'license_reference' => $subscription->license_reference, 'restaurant_uuid' => $subscription->restaurant->restaurant_uuid,
            'restaurant_name' => $subscription->restaurant->name, 'installation_uuid' => $installation->installation_uuid,
            'device_fingerprint' => $installation->device_fingerprint,
            'activation_method' => $installation->activation_method ?: 'online',
            'plan' => $subscription->plan->slug, 'status' => $this->status($subscription), 'features' => $subscription->plan->features,
            'max_paired_tables' => $subscription->plan->max_paired_tables, 'issued_at' => now()->toIso8601String(),
            'limits' => ['paired_tables' => $subscription->plan->max_paired_tables],
            'starts_at' => $subscription->starts_at->toIso8601String(), 'expires_at' => $subscription->expires_at?->toIso8601String(),
            'subscription_expires_at' => $subscription->expires_at?->toIso8601String(),
            'offline_verification_due_at' => null,
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
        ];

        return $this->signatures->sign(array_merge($payload, $additionalClaims));
    }

    public function status(CloudSubscription $subscription): string
    {
        if ($subscription->restaurant && $subscription->restaurant->status !== 'active') return 'suspended';
        if (in_array($subscription->status, ['suspended', 'expired'], true)) return $subscription->status;
        if ($subscription->status === 'grace') {
            return $subscription->grace_ends_at && now()->lessThanOrEqualTo($subscription->grace_ends_at) ? 'grace' : 'expired';
        }
        if (! $subscription->expires_at || now()->lessThanOrEqualTo($subscription->expires_at)) return $subscription->status === 'trial' ? 'trial' : 'active';
        return $subscription->grace_ends_at && now()->lessThanOrEqualTo($subscription->grace_ends_at) ? 'grace' : 'expired';
    }

    private function assertRequestMatchesInstallation(array $payload, ?CloudInstallation $installation): void
    {
        if (! $installation) return;
        if ($installation->status !== 'active') {
            $this->invalid('activation_request', 'This server was deactivated or transferred. Export the request from the replacement server.');
        }
        if ($installation->device_fingerprint
            && ! hash_equals((string) $installation->device_fingerprint, (string) $payload['device_fingerprint'])) {
            $this->invalid('activation_request', 'The activation request does not match this installation\'s computer fingerprint. Use the licence transfer workflow.');
        }
        if ($installation->request_public_key
            && ! hash_equals((string) $installation->request_public_key, (string) $payload['request_public_key'])) {
            $this->invalid('activation_request', 'The activation request identity does not match the key already registered for this installation.');
        }
    }

    private function assertSigningReady(string $field): void
    {
        if (! $this->signatures->signingReady()) {
            $this->invalid($field, 'TablePlay Cloud licence signing is not configured correctly. Configure the Ed25519 signing keypair before issuing licences.');
        }
    }

    private function normalizeKey(string $key): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($key)));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function log(?string $uuid, string $event, string $status, array $metadata = [], ?string $error = null): void
    {
        LicenseSyncLog::create(['installation_uuid' => $uuid, 'event' => $event, 'status' => $status, 'metadata' => $metadata, 'error' => $error]);
    }
}
