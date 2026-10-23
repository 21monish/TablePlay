<?php

namespace Tests\Feature;

use App\Models\{CommercialPlan, RestaurantSubscription, TablePlayInstallation};
use App\Services\{EntitlementService, LicenseSignatureService, LocalLicenseService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LicenseDatePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $withTestLicense = false;
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        $keys = app(LicenseSignatureService::class)->generateKeyPair();
        config()->set([
            'tableplay.license_key_id' => 'date-test-v1',
            'tableplay.license_public_key' => $keys['public_key'],
            'tableplay.license_private_key' => $keys['private_key'],
            'tableplay.trusted_license_public_keys' => ['date-test-v1' => $keys['public_key']],
            'tableplay.device_fingerprint_override' => 'date-persistence-test',
        ]);
        $this->travelTo(Carbon::parse('2026-10-05T12:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    public function test_utc_cloud_dates_remain_verified_on_an_indian_restaurant_server(): void
    {
        $this->setTimezone('Asia/Kolkata');
        $envelope = $this->envelope();
        $licenses = app(LocalLicenseService::class);
        $subscription = $licenses->installEnvelope($envelope, 'cloud');
        $this->assertDatesMatch($subscription, $envelope);
        $this->assertSame('2026-10-04 18:48:31', $subscription->getRawOriginal('starts_at'));
        $this->assertTrue(app(EntitlementService::class)->state()['signature_valid']);
        $this->assertTrue(app(EntitlementService::class)->state()['licensed']);

        $again = $licenses->installEnvelope($envelope, 'cloud');
        $again->update(['last_verified_at' => now()->addMinute()]);
        $this->assertDatesMatch($again->fresh(), $envelope);
        $this->assertTrue(app(EntitlementService::class)->state()['licensed']);

        $again->update(['expires_at' => $again->expires_at->copy()->addDay()]);
        $this->assertFalse(app(EntitlementService::class)->state()['signature_valid']);
    }

    public function test_indian_signed_dates_remain_verified_on_a_utc_server(): void
    {
        $this->setTimezone('UTC');
        $envelope = $this->envelope('+05:30');
        $subscription = app(LocalLicenseService::class)->installEnvelope($envelope, 'cloud');
        $this->assertDatesMatch($subscription, $envelope);
        $this->assertTrue(app(EntitlementService::class)->state()['licensed']);
    }

    public function test_legacy_limit_normalization_does_not_rewrite_signed_claims(): void
    {
        $this->setTimezone('UTC');
        $envelope = $this->envelope();
        unset($envelope['payload']['limits']);
        $envelope = app(LicenseSignatureService::class)->sign($envelope['payload']);
        $subscription = app(LocalLicenseService::class)->installEnvelope($envelope, 'cloud');
        $stored = json_decode($subscription->signed_payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('limits', $stored);
        $this->assertSame(3, $subscription->entitlement_limits['paired_tables']);
        $this->assertTrue(app(EntitlementService::class)->state()['signature_valid']);
    }

    public function test_import_cannot_report_success_if_the_database_changes_a_signed_date(): void
    {
        $this->setTimezone('UTC');
        $envelope = $this->envelope();
        Event::listen('eloquent.saved: '.RestaurantSubscription::class, function ($subscription): void {
            DB::table('restaurant_subscriptions')->where('id', $subscription->id)->update(['starts_at' => now()]);
        });
        try {
            app(LocalLicenseService::class)->installEnvelope($envelope, 'cloud');
            $this->fail('A corrupted stored licence must not report a successful import.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('license', $error->errors());
        }
        $this->assertDatabaseCount('restaurant_subscriptions', 0);
        $this->assertNull(TablePlayInstallation::firstOrFail()->license_reference);
    }

    private function setTimezone(string $timezone): void
    {
        config()->set('app.timezone', $timezone);
        date_default_timezone_set($timezone);
    }

    private function envelope(string $offset = '+00:00'): array
    {
        $installation = app(LocalLicenseService::class)->installation();
        $plan = CommercialPlan::where('slug', 'trial')->firstOrFail();
        return app(LicenseSignatureService::class)->sign([
            'license_uuid' => (string) Str::uuid(),
            'license_reference' => 'TP-DATE-PERSISTENCE',
            'license_revision' => 1,
            'installation_uuid' => $installation->installation_uuid,
            'device_fingerprint' => $installation->device_fingerprint,
            'activation_method' => 'online',
            'plan' => $plan->slug, 'status' => 'trial', 'features' => $plan->features,
            'limits' => ['paired_tables' => 3], 'max_paired_tables' => 3,
            'issued_at' => '2026-10-05T11:00:00'.$offset,
            'starts_at' => '2026-10-04T13:18:31'.$offset,
            'expires_at' => '2026-10-18T13:18:31'.$offset,
            'subscription_expires_at' => '2026-10-18T13:18:31'.$offset,
            'grace_ends_at' => '2026-10-25T13:18:31'.$offset,
        ]);
    }

    private function assertDatesMatch(RestaurantSubscription $subscription, array $envelope): void
    {
        foreach (['starts_at', 'expires_at', 'subscription_expires_at', 'grace_ends_at'] as $field) {
            $this->assertSame(Carbon::parse($envelope['payload'][$field])->timestamp, $subscription->$field->timestamp, $field);
        }
        $this->assertSame(app(LicenseSignatureService::class)->canonicalJson($envelope['payload']), $subscription->signed_payload);
    }
}
