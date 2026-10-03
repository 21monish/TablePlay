<?php

namespace App\Services;

use App\Models\{CommercialPlan, DevicePairing, RestaurantSubscription, TablePlayInstallation};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EntitlementService
{
    private const LOCAL_PROVENANCE = 'tableplay-local-entitlement-v1';

    public function __construct(private LicenseSignatureService $signatures) {}

    public function subscription(): ?RestaurantSubscription
    {
        return RestaurantSubscription::with('plan')->latest('id')->first();
    }

    public function state(): array
    {
        $subscription = $this->subscription();
        $usage = ['paired_tables' => DevicePairing::where('is_active', true)->count()];
        if (! $subscription) return [
            'licensed' => false, 'signature_valid' => false, 'plan' => null, 'status' => 'missing',
            'features' => [], 'limits' => [], 'usage' => $usage, 'warnings' => [],
            'starts_at' => null, 'expires_at' => null, 'subscription_expires_at' => null,
            'offline_verification_due_at' => null, 'grace_ends_at' => null, 'days_remaining' => null,
            'verification_current' => false, 'license_reference' => null, 'source' => null,
        ];

        $clockValid = ! $subscription->last_verified_at
            || now()->greaterThanOrEqualTo($subscription->last_verified_at->copy()->subMinutes(10));
        $signatureValid = $clockValid && $this->verify($subscription);
        $status = $subscription->status;
        if ($subscription->starts_at && now()->lessThan($subscription->starts_at)) $status = 'pending';
        $commercialExpiry = $subscription->subscription_expires_at ?? $subscription->expires_at;
        $offlineVerificationDue = $subscription->offline_verification_due_at;
        if (in_array($status, ['trial', 'active', 'grace'], true)
            && $commercialExpiry && now()->greaterThan($commercialExpiry)) {
            $status = $subscription->grace_ends_at && now()->lessThanOrEqualTo($subscription->grace_ends_at) ? 'grace' : 'expired';
        }
        if (! $signatureValid) $status = 'invalid';
        $features = $signatureValid ? ($subscription->entitlement_snapshot ?: $subscription->plan->features) : [];
        $verificationCurrent = $subscription->source !== 'offline'
            || ! $offlineVerificationDue
            || now()->lessThanOrEqualTo($offlineVerificationDue);
        $licensed = $signatureValid && $verificationCurrent && in_array($status, ['trial', 'active', 'grace'], true);
        $daysRemaining = $commercialExpiry
            ? (int) max(0, now()->startOfDay()->diffInDays($commercialExpiry->copy()->startOfDay(), false))
            : null;
        $limits = is_array($subscription->entitlement_limits)
            ? $subscription->entitlement_limits
            : ['paired_tables' => $subscription->plan?->max_paired_tables];

        return [
            'licensed' => $licensed, 'signature_valid' => $signatureValid, 'source' => $subscription->source ?? 'legacy',
            'plan' => $subscription->plan?->only(['slug', 'name']), 'status' => $status,
            'starts_at' => $subscription->starts_at, 'expires_at' => $commercialExpiry,
            'subscription_expires_at' => $commercialExpiry,
            'offline_verification_due_at' => $offlineVerificationDue,
            'grace_ends_at' => $subscription->grace_ends_at,
            'days_remaining' => $daysRemaining, 'verification_current' => $verificationCurrent,
            'features' => $features, 'limits' => $limits, 'usage' => $usage,
            'license_reference' => $subscription->license_reference,
            'warnings' => $this->warnings($subscription, $status, $signatureValid, $clockValid, $verificationCurrent, $daysRemaining),
        ];
    }

    public function allows(string $feature): bool
    {
        $state = $this->state();
        return $state['licensed'] && (bool) data_get($state, 'features.'.$feature, false);
    }

    public function assertFeature(string $feature): void
    {
        $state = $this->state();

        if ($state['licensed'] && (bool) data_get($state, 'features.'.$feature, false)) {
            return;
        }

        throw new AuthorizationException(
            $state['licensed']
                ? 'Your current TablePlay plan does not include this feature.'
                : 'This feature is unavailable because the TablePlay licence is not active.'
        );
    }

    public function assertCanPair(): void
    {
        $state = $this->state();
        if (! $state['licensed']) throw ValidationException::withMessages(['license' => 'Renew or activate TablePlay before pairing another customer tablet.']);
        if (! (bool) data_get($state, 'features.customer_app', false)) throw ValidationException::withMessages(['license' => 'Your current TablePlay plan does not include the Customer Table app.']);
        $limit = data_get($state, 'limits.paired_tables');
        if ($limit !== null && $state['usage']['paired_tables'] >= $limit) throw ValidationException::withMessages(['license' => "Your {$state['plan']['name']} plan allows {$limit} paired table tablets. Upgrade the plan to pair another device."]);
    }

    /**
     * Enforce Customer Table access on every request, including devices that were
     * paired before a plan downgrade. When a lower limit is applied, the oldest
     * active pairings remain usable and later pairings are blocked deterministically.
     */
    public function assertCustomerAppAccess(DevicePairing $pairing): void
    {
        $state = $this->state();

        if (! $state['licensed']) {
            throw new AuthorizationException(
                'The TablePlay licence needs renewal. Restaurant staff can still complete existing kitchen and billing work.'
            );
        }

        if (! (bool) data_get($state, 'features.customer_app', false)) {
            throw new AuthorizationException(
                'Your current TablePlay plan does not include the Customer Table app.'
            );
        }

        $limit = data_get($state, 'limits.paired_tables');
        if ($limit === null) return;

        $limit = max(0, (int) $limit);
        $allowedPairingIds = DevicePairing::query()
            ->where('is_active', true)
            ->orderBy('paired_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if (! $allowedPairingIds->contains($pairing->id)) {
            $planName = data_get($state, 'plan.name', 'current');
            throw new AuthorizationException(
                "Your {$planName} plan allows {$limit} active table tablet".($limit === 1 ? '' : 's').'. Unpair another tablet or upgrade the plan.'
            );
        }
    }

    public function assertCanOpenSession(): void
    {
        if (! $this->state()['licensed']) throw ValidationException::withMessages(['license' => 'The TablePlay licence needs renewal. Existing visits, kitchen work, billing and payments remain available, but a new table session cannot be opened.']);
    }

    public function activate(CommercialPlan $plan, string $status = 'active', ?int $days = null): RestaurantSubscription
    {
        if (! $this->localPlanChangesAllowed()) throw new RuntimeException('Plan changes must be issued by TablePlay Cloud.');
        if (! in_array($status, ['trial', 'active'], true)) throw new RuntimeException('A local plan can only be activated as trial or active.');

        $installation = TablePlayInstallation::firstOrCreate(
            ['id' => 1],
            ['installation_uuid' => (string) Str::uuid()],
        );
        $starts = now();
        $expires = $days ? $starts->copy()->addDays($days) : null;
        $graceEnds = $expires?->copy()->addDays(7);
        $reference = 'TP-LOCAL-'.strtoupper(Str::random(12));
        $features = $plan->features;
        $limits = ['paired_tables' => $plan->max_paired_tables];
        $payload = $this->localPayload(
            $installation->installation_uuid,
            $reference,
            $plan->slug,
            $status,
            $features,
            $limits,
            $starts,
            $starts,
            $expires,
            $graceEnds,
        );
        $serialized = $this->signatures->canonicalJson($payload);

        return DB::transaction(function () use ($installation, $plan, $reference, $status, $starts, $expires, $graceEnds, $features, $limits, $serialized) {
            RestaurantSubscription::query()->update(['status' => 'expired']);
            $subscription = RestaurantSubscription::create([
                'commercial_plan_id' => $plan->id,
                'installation_uuid' => $installation->installation_uuid,
                'license_reference' => $reference,
                'license_uuid' => null,
                'license_revision' => 0,
                'status' => $status,
                'source' => 'local',
                'starts_at' => $starts,
                'expires_at' => $expires,
                'subscription_expires_at' => $expires,
                'offline_verification_due_at' => null,
                'grace_ends_at' => $graceEnds,
                'entitlement_snapshot' => $features,
                'entitlement_limits' => $limits,
                'signed_payload' => $serialized,
                'signature' => $this->localSignature($serialized),
                'license_key_id' => null,
                'last_verified_at' => now(),
                'verification_error' => null,
            ]);

            $installation->update([
                'license_reference' => $reference,
                'license_revision' => 0,
                'license_content_hash' => null,
                'last_license_issued_at' => $starts,
            ]);

            return $subscription->fresh('plan');
        });
    }

    /**
     * Change a deliberately local development licence without leaving mutable,
     * unsigned status behind. Cloud and offline licences must be changed at the
     * signing authority and reinstalled instead.
     */
    public function setLocalStatus(RestaurantSubscription $subscription, string $status): RestaurantSubscription
    {
        if (! in_array($status, ['trial', 'active', 'grace', 'suspended', 'expired'], true)) {
            throw new RuntimeException('The requested local licence status is not supported.');
        }

        $isBootstrap = ($subscription->source ?? '') === 'legacy'
            && $this->verifyBootstrap($subscription)
            && $this->localPlanChangesAllowed();
        if (! $isBootstrap && (($subscription->source ?? '') !== 'local' || ! $this->verifyLocal($subscription))) {
            throw new RuntimeException('Signed TablePlay licences must be changed by TablePlay Cloud.');
        }

        $issuedAt = now();
        $reference = $isBootstrap
            ? 'TP-LOCAL-'.strtoupper(Str::random(12))
            : $subscription->license_reference;
        $limits = $isBootstrap
            ? ['paired_tables' => $subscription->plan?->max_paired_tables]
            : $subscription->entitlement_limits;
        $payload = $this->localPayload(
            $subscription->installation_uuid,
            $reference,
            (string) $subscription->plan?->slug,
            $status,
            $subscription->entitlement_snapshot,
            $limits,
            $issuedAt,
            $subscription->starts_at,
            $subscription->expires_at,
            $subscription->grace_ends_at,
        );
        $serialized = $this->signatures->canonicalJson($payload);

        DB::transaction(function () use ($subscription, $status, $reference, $limits, $serialized, $issuedAt, $isBootstrap) {
            $subscription->update([
                'source' => 'local',
                'license_reference' => $reference,
                'entitlement_limits' => $limits,
                'status' => $status,
                'signed_payload' => $serialized,
                'signature' => $this->localSignature($serialized),
                'last_verified_at' => $issuedAt,
                'verification_error' => null,
            ]);
            if ($isBootstrap) {
                TablePlayInstallation::where('installation_uuid', $subscription->installation_uuid)->update([
                    'license_reference' => $reference,
                    'last_license_issued_at' => $issuedAt,
                ]);
            }
        });

        return $subscription->fresh('plan');
    }

    private function verify(RestaurantSubscription $subscription): bool
    {
        return match ((string) ($subscription->source ?? 'legacy')) {
            'legacy' => $this->verifyBootstrap($subscription),
            'local' => $this->verifyLocal($subscription),
            'cloud', 'offline' => $this->verifySigned($subscription),
            default => false,
        };
    }

    private function verifySigned(RestaurantSubscription $subscription): bool
    {
        $payload = json_decode((string) $subscription->signed_payload, true);
        if (! is_array($payload)) return false;
        $expectedMethod = $subscription->source === 'offline' ? 'offline' : 'online';
        if (! hash_equals($expectedMethod, (string) ($payload['activation_method'] ?? ''))
            || ! $subscription->license_uuid
            || (int) $subscription->license_revision < 1
            || blank($subscription->license_key_id)) return false;

        $valid = $this->signatures->verify(['version' => 1, 'algorithm' => 'Ed25519', 'key_id' => $subscription->license_key_id, 'payload' => $payload, 'signature' => $subscription->signature]);
        if ($valid) {
            $installation = TablePlayInstallation::first();
            $limits = is_array($subscription->entitlement_limits)
                ? $subscription->entitlement_limits
                : ['paired_tables' => $subscription->plan?->max_paired_tables];
            $valid = $installation
                && hash_equals($installation->installation_uuid, (string) ($payload['installation_uuid'] ?? ''))
                && hash_equals((string) $installation->device_fingerprint, (string) ($payload['device_fingerprint'] ?? ''))
                && hash_equals((string) $installation->license_reference, $subscription->license_reference)
                && (int) $installation->license_revision === (int) $subscription->license_revision
                && filled($installation->license_content_hash)
                && hash_equals($subscription->license_reference, (string) ($payload['license_reference'] ?? ''))
                && hash_equals((string) $subscription->license_uuid, (string) ($payload['license_uuid'] ?? ''))
                && (int) $subscription->license_revision === (int) ($payload['license_revision'] ?? 0)
                && hash_equals($subscription->status, (string) ($payload['status'] ?? ''))
                && hash_equals((string) $subscription->plan?->slug, (string) ($payload['plan'] ?? ''))
                && $this->sameStructuredValue($subscription->entitlement_snapshot, $payload['features'] ?? null)
                && $this->sameStructuredValue($limits, $payload['limits'] ?? ['paired_tables' => $payload['max_paired_tables'] ?? null])
                && $this->sameInstant($subscription->starts_at, $payload['starts_at'] ?? null)
                && $this->sameInstant($subscription->expires_at, $payload['expires_at'] ?? null)
                && $this->sameInstant($subscription->subscription_expires_at, $payload['subscription_expires_at'] ?? $payload['expires_at'] ?? null)
                && $this->sameInstant(
                    $subscription->offline_verification_due_at,
                    $subscription->source === 'offline'
                        ? ($payload['offline_verification_due_at'] ?? $payload['offline_refresh_due_at'] ?? $payload['expires_at'] ?? null)
                        : null,
                )
                && $this->sameInstant($subscription->grace_ends_at, $payload['grace_ends_at'] ?? null);
            if ($valid) {
                $contentPayload = $payload;
                unset($contentPayload['issued_at']);
                $valid = hash_equals(
                    (string) $installation->license_content_hash,
                    hash('sha256', $this->signatures->canonicalJson($contentPayload)),
                );
            }
        }
        return $valid;
    }

    private function verifyLocal(RestaurantSubscription $subscription): bool
    {
        if (! $this->localPlanChangesAllowed()
            || ! str_starts_with($subscription->license_reference, 'TP-LOCAL-')
            || ! $this->hasLocalIdentityShape($subscription)) return false;

        $payload = $this->verifiedLocalPayload($subscription);
        if (! $payload) return false;
        if (! array_key_exists('provenance', $payload)) return true;

        if (($payload['provenance'] ?? null) !== self::LOCAL_PROVENANCE
            || ($payload['source'] ?? null) !== 'local'
            || ! hash_equals($subscription->installation_uuid, (string) ($payload['installation_uuid'] ?? ''))
            || ! hash_equals($subscription->license_reference, (string) ($payload['license_reference'] ?? ''))
            || ! hash_equals((string) $subscription->plan?->slug, (string) ($payload['plan'] ?? ''))
            || ! hash_equals($subscription->status, (string) ($payload['status'] ?? ''))
            || ! $this->sameStructuredValue($subscription->entitlement_snapshot, $payload['features'] ?? null)
            || ! $this->sameStructuredValue($subscription->entitlement_limits, $payload['limits'] ?? null)
            || ! $this->sameInstant($subscription->starts_at, $payload['starts_at'] ?? null)
            || ! $this->sameInstant($subscription->expires_at, $payload['expires_at'] ?? null)
            || ! $this->sameInstant($subscription->grace_ends_at, $payload['grace_ends_at'] ?? null)) return false;

        return $this->validIssuedAt($payload['issued_at'] ?? null);
    }

    private function verifyBootstrap(RestaurantSubscription $subscription): bool
    {
        if (! str_starts_with($subscription->license_reference, 'TP-TRIAL-')
            || $subscription->status !== 'trial'
            || ! $this->hasLocalIdentityShape($subscription, true)) return false;

        $payload = $this->verifiedLocalPayload($subscription);
        if (! $payload || ($payload['plan'] ?? null) !== 'trial'
            || (string) $subscription->plan?->slug !== 'trial'
            || ! $this->sameStructuredValue($subscription->entitlement_snapshot, $payload['features'] ?? null)
            || $subscription->entitlement_limits !== null
            || $subscription->plan?->max_paired_tables !== ($payload['max_paired_tables'] ?? null)
            || ! $this->sameInstant($subscription->starts_at, $payload['issued_at'] ?? null)
            || ! $this->sameInstant($subscription->expires_at, $payload['expires_at'] ?? null)) return false;

        $expectedGrace = $subscription->expires_at?->copy()->addDays(7);
        return $this->sameInstant($subscription->grace_ends_at, $expectedGrace?->toIso8601String());
    }

    /** Support authenticated local records created before provenance v1. */
    private function verifyLegacyLocalPayload(RestaurantSubscription $subscription, array $payload): bool
    {
        $expectedStatus = ($payload['plan'] ?? null) === 'trial' ? 'trial' : 'active';
        if ($subscription->status !== $expectedStatus
            || ! hash_equals((string) $subscription->plan?->slug, (string) ($payload['plan'] ?? ''))
            || ! $this->sameStructuredValue($subscription->entitlement_snapshot, $payload['features'] ?? null)
            || $subscription->entitlement_limits !== null
            || $subscription->plan?->max_paired_tables !== ($payload['max_paired_tables'] ?? null)
            || ! $this->sameInstant($subscription->starts_at, $payload['issued_at'] ?? null)
            || ! $this->sameInstant($subscription->expires_at, $payload['expires_at'] ?? null)) return false;

        $expectedGrace = $subscription->expires_at?->copy()->addDays(7);
        return $this->sameInstant($subscription->grace_ends_at, $expectedGrace?->toIso8601String());
    }

    private function hasLocalIdentityShape(RestaurantSubscription $subscription, bool $bootstrap = false): bool
    {
        if ($subscription->license_uuid !== null
            || (int) $subscription->license_revision !== 0
            || filled($subscription->license_key_id)) return false;

        $installation = TablePlayInstallation::first();
        if (! $installation
            || ! hash_equals($installation->installation_uuid, $subscription->installation_uuid)
            || (int) $installation->license_revision !== 0
            || filled($installation->license_content_hash)) return false;

        return ! $bootstrap || hash_equals((string) $installation->license_reference, $subscription->license_reference);
    }

    private function verifiedLocalPayload(RestaurantSubscription $subscription): ?array
    {
        $serialized = (string) $subscription->signed_payload;
        $signature = (string) $subscription->signature;
        if (blank(config('app.key')) || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)
            || ! hash_equals($this->localSignature($serialized), $signature)) return null;

        $payload = json_decode($serialized, true);
        if (! is_array($payload) || array_is_list($payload)) return null;
        if (! array_key_exists('provenance', $payload)) {
            return $this->verifyLegacyLocalPayload($subscription, $payload) ? $payload : null;
        }

        return $payload;
    }

    private function localPayload(
        string $installationUuid,
        string $reference,
        string $plan,
        string $status,
        array $features,
        array $limits,
        mixed $issuedAt,
        mixed $startsAt,
        mixed $expiresAt,
        mixed $graceEndsAt,
    ): array {
        return [
            'provenance' => self::LOCAL_PROVENANCE,
            'source' => 'local',
            'installation_uuid' => $installationUuid,
            'license_reference' => $reference,
            'plan' => $plan,
            'status' => $status,
            'features' => $features,
            'limits' => $limits,
            'issued_at' => $issuedAt?->toIso8601String(),
            'starts_at' => $startsAt?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'grace_ends_at' => $graceEndsAt?->toIso8601String(),
        ];
    }

    private function localSignature(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function localPlanChangesAllowed(): bool
    {
        return app()->environment(['local', 'testing'])
            || config('tableplay.mode') === 'cloud'
            || (bool) config('tableplay.allow_local_plan_changes');
    }

    private function validIssuedAt(mixed $issuedAt): bool
    {
        if (! is_string($issuedAt) || blank($issuedAt)) return false;
        try { return \Illuminate\Support\Carbon::parse($issuedAt)->lessThanOrEqualTo(now()->addMinutes(10)); }
        catch (\Throwable) { return false; }
    }

    private function sameInstant(mixed $stored, mixed $signed): bool
    {
        if ($stored === null || blank($signed)) return $stored === null && blank($signed);
        try { return $stored->getTimestamp() === \Illuminate\Support\Carbon::parse($signed)->getTimestamp(); }
        catch (\Throwable) { return false; }
    }

    private function sameStructuredValue(mixed $stored, mixed $signed): bool
    {
        if (! is_array($stored) || ! is_array($signed)) return false;
        try { return hash_equals($this->signatures->canonicalJson($stored), $this->signatures->canonicalJson($signed)); }
        catch (\Throwable) { return false; }
    }

    private function warnings(RestaurantSubscription $subscription, string $status, bool $signatureValid, bool $clockValid, bool $verificationCurrent, ?int $daysRemaining): array
    {
        if (! $clockValid) return [['level' => 'danger', 'message' => 'The server clock was moved backwards after licence verification. Correct the date and time, then verify the licence again.']];
        if (! $signatureValid) return [['level' => 'danger', 'message' => 'The installed TablePlay licence signature is invalid. Contact TablePlay support.']];
        if ($status === 'grace') return [['level' => 'warning', 'message' => 'The subscription has expired and is in its grace period. Renew before new table service is disabled.']];
        if ($status === 'expired') return [['level' => 'danger', 'message' => 'The subscription grace period has ended. Renew to open new table sessions.']];
        if ($status === 'suspended') return [['level' => 'danger', 'message' => 'This TablePlay subscription is suspended.']];
        if ($status === 'pending') return [['level' => 'warning', 'message' => 'This TablePlay licence is valid but its start date has not arrived yet.']];
        if (! $verificationCurrent) return [[
            'level' => 'danger',
            'message' => 'The offline verification lease is due. Import a freshly signed licence file; the commercial subscription date has not been changed.',
        ]];
        if ($daysRemaining === null) return [];
        foreach ([1, 3, 7, 14, 30] as $threshold) if ($daysRemaining <= $threshold) return [[
            'level' => $daysRemaining <= 7 ? 'danger' : 'warning',
            'message' => $daysRemaining === 0
                ? 'TablePlay expires today. Renew to avoid entering the grace period.'
                : "TablePlay expires in {$daysRemaining} day".($daysRemaining === 1 ? '' : 's').'. Renew to avoid entering the grace period.',
        ]];
        return [];
    }
}
