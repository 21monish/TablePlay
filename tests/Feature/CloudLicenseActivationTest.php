<?php

namespace Tests\Feature;

use App\Models\{CloudInstallation, CloudLicenseKey, CloudRestaurant, CloudSubscription, CommercialPlan};
use App\Services\{CloudLicenseService, LicenseSignatureService};
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PostgresAggregateLockGuard;
use Tests\TestCase;

class CloudLicenseActivationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $withTestLicense = false;
    private Grammar $originalGrammar;
    private PostgresAggregateLockGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $keys = app(LicenseSignatureService::class)->generateKeyPair();
        config()->set([
            'tableplay.mode' => 'cloud',
            'tableplay.license_key_id' => 'activation-test-v1',
            'tableplay.license_public_key' => $keys['public_key'],
            'tableplay.license_private_key' => $keys['private_key'],
        ]);
        $connection = DB::connection();
        $this->originalGrammar = $connection->getQueryGrammar();
        $this->guard = new PostgresAggregateLockGuard($connection);
        $connection->setQueryGrammar($this->guard);
    }

    protected function tearDown(): void
    {
        DB::connection()->setQueryGrammar($this->originalGrammar);
        parent::tearDown();
    }

    public function test_valid_key_activates_and_syncs_a_signed_licence_without_locking_an_aggregate(): void
    {
        $subscription = $this->subscription();
        $key = app(CloudLicenseService::class)->issueKey($subscription);
        $data = $this->activation($key);

        $response = $this->postJson('/api/cloud/v1/licenses/activate', $data)
            ->assertOk()->assertJsonPath('license.payload.activation_method', 'online');
        $this->assertTrue(app(LicenseSignatureService::class)->verify($response->json('license')));
        $this->assertContains('cloud_subscriptions', $this->guard->lockedTables);
        $this->assertFalse(CloudLicenseKey::firstOrFail()->is_active);
        $this->assertDatabaseCount('cloud_installations', 1);

        $this->withToken($response->json('installation_token'))
            ->postJson('/api/cloud/v1/licenses/sync', [
                'installation_uuid' => $data['installation_uuid'],
                'device_fingerprint' => $data['device_fingerprint'],
            ])->assertOk()->assertJsonPath('license.payload.installation_uuid', $data['installation_uuid']);
        $this->postJson('/api/cloud/v1/licenses/activate', $data)->assertUnprocessable();
        $this->assertDatabaseCount('cloud_installations', 1);
    }

    public function test_server_limit_rejects_a_second_installation_without_consuming_its_key(): void
    {
        $subscription = $this->subscription();
        $service = app(CloudLicenseService::class);
        $this->postJson('/api/cloud/v1/licenses/activate', $this->activation($service->issueKey($subscription)))->assertOk();
        $secondKey = $service->issueKey($subscription);

        $this->postJson('/api/cloud/v1/licenses/activate', $this->activation($secondKey))
            ->assertUnprocessable()->assertJsonValidationErrors('license_key');
        $this->assertTrue(CloudLicenseKey::latest('id')->firstOrFail()->is_active);
        $this->assertSame(1, CloudInstallation::where('cloud_subscription_id', $subscription->id)->where('status', 'active')->count());
    }

    private function activation(string $key): array
    {
        return [
            'license_key' => $key,
            'installation_uuid' => (string) Str::uuid(),
            'device_name' => 'Activation test server',
            'device_fingerprint' => hash('sha256', (string) Str::uuid()),
            'server_version' => 'activation-test',
        ];
    }

    private function subscription(): CloudSubscription
    {
        $restaurant = CloudRestaurant::create([
            'restaurant_uuid' => (string) Str::uuid(), 'name' => 'Activation test restaurant', 'status' => 'active',
        ]);
        return CloudSubscription::create([
            'cloud_restaurant_id' => $restaurant->id,
            'commercial_plan_id' => CommercialPlan::where('slug', 'trial')->firstOrFail()->id,
            'license_reference' => 'TP-'.Str::upper(Str::random(14)),
            'status' => 'trial', 'starts_at' => now()->subMinute(), 'expires_at' => now()->addDays(30),
            'grace_ends_at' => now()->addDays(37), 'max_installations' => 1, 'license_revision' => 1,
        ]);
    }
}
