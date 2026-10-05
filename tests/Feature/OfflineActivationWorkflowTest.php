<?php

namespace Tests\Feature;

use App\Models\{
    CloudInstallation,
    CloudOfflineActivationRequest,
    CloudRestaurant,
    CloudSubscription,
    CommercialPlan,
    Role,
    TablePlayInstallation,
    User
};
use App\Services\{EntitlementService, LicenseSignatureService, LocalLicenseService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Grammars\Grammar;
use Tests\Support\PostgresAggregateLockGuard;
use Tests\TestCase;

class OfflineActivationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $superadmin;
    private array $keys;
    private Grammar $originalGrammar;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tableplay.device_fingerprint_override', 'complete-offline-workflow-device');
        $this->keys = app(LicenseSignatureService::class)->generateKeyPair();
        config()->set('tableplay.license_key_id', 'workflow-v1');
        config()->set('tableplay.license_public_key', $this->keys['public_key']);
        config()->set('tableplay.license_private_key', $this->keys['private_key']);
        config()->set('tableplay.trusted_license_public_keys', ['workflow-v1' => $this->keys['public_key']]);

        $this->admin = $this->user('admin');
        $this->superadmin = $this->user('superadmin');
        $connection = DB::connection();
        $this->originalGrammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new PostgresAggregateLockGuard($connection));
    }

    protected function tearDown(): void
    {
        DB::connection()->setQueryGrammar($this->originalGrammar);
        parent::tearDown();
    }

    public function test_complete_usb_only_activation_round_trip(): void
    {
        $installation = app(LocalLicenseService::class)->installation();
        $subscription = $this->cloudSubscription();

        $requestResponse = $this->actingAs($this->admin)->post(route('admin.license.request'));
        $requestResponse->assertOk()->assertDownload();
        $this->assertStringContainsString('application/vnd.tableplay.activation-request+json', (string) $requestResponse->headers->get('content-type'));
        $requestDocument = json_decode($requestResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $requestJson = json_encode($requestDocument, JSON_THROW_ON_ERROR);

        $this->assertSame($installation->installation_uuid, data_get($requestDocument, 'payload.installation_uuid'));
        $this->assertSame(hash('sha256', 'complete-offline-workflow-device'), data_get($requestDocument, 'payload.device_fingerprint'));
        $this->assertArrayNotHasKey('key_id', $requestDocument);
        $this->assertStringNotContainsString($this->keys['private_key'], $requestJson);
        $this->assertStringNotContainsString((string) config('app.key'), $requestJson);

        $issueResponse = $this->actingAs($this->superadmin)->post(
            route('superadmin.cloud.offline-requests.import'),
            [
                'subscription_id' => $subscription->id,
                'activation_request' => UploadedFile::fake()->createWithContent('restaurant.tpr', $requestJson),
            ],
        );
        $issueResponse->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('issued_offline_request_id');

        $offlineRequest = CloudOfflineActivationRequest::firstOrFail();
        $this->assertSame('issued', $offlineRequest->status);
        $this->assertSame($subscription->id, $offlineRequest->cloud_subscription_id);
        $this->assertSame($installation->installation_uuid, $offlineRequest->installation_uuid);
        $this->assertDatabaseHas('cloud_installations', [
            'installation_uuid' => $installation->installation_uuid,
            'activation_method' => 'offline',
            'device_fingerprint' => hash('sha256', 'complete-offline-workflow-device'),
            'status' => 'active',
        ]);

        $licenseResponse = $this->actingAs($this->superadmin)
            ->get(route('superadmin.cloud.offline-requests.license', $offlineRequest));
        $licenseResponse->assertOk()->assertDownload();
        $license = json_decode($licenseResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue(app(LicenseSignatureService::class)->verify($license));
        $this->assertSame(data_get($requestDocument, 'payload.request_id'), data_get($license, 'payload.offline_request_id'));
        $this->assertSame(data_get($requestDocument, 'payload.request_public_key'), data_get($license, 'payload.request_public_key'));
        $this->assertSame(data_get($license, 'payload.offline_refresh_due_at'), data_get($license, 'payload.offline_verification_due_at'));
        $this->assertSame($subscription->expires_at->toIso8601String(), data_get($license, 'payload.subscription_expires_at'));
        $this->assertSame($subscription->expires_at->toIso8601String(), data_get($license, 'payload.expires_at'));
        $this->assertNotSame(data_get($license, 'payload.offline_verification_due_at'), data_get($license, 'payload.expires_at'));

        $importResponse = $this->actingAs($this->admin)->post(route('admin.license.import'), [
            'license_file' => UploadedFile::fake()->createWithContent(
                'tableplay-license.tpl',
                json_encode($license, JSON_THROW_ON_ERROR),
            ),
        ]);
        $importResponse->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status');

        $state = app(EntitlementService::class)->state();
        $this->assertTrue($state['licensed']);
        $this->assertTrue($state['signature_valid']);
        $this->assertSame('best', data_get($state, 'plan.slug'));
        $this->assertSame(5, data_get($state, 'limits.paired_tables'));
        $this->assertTrue($state['verification_current']);
        $this->assertSame(
            $subscription->expires_at->timestamp,
            $state['subscription_expires_at']->timestamp,
        );
        $this->assertSame(
            \Illuminate\Support\Carbon::parse(data_get($license, 'payload.offline_verification_due_at'))->timestamp,
            $state['offline_verification_due_at']->timestamp,
        );
        $this->assertSame('fulfilled', $offlineRequest->fresh()->status === 'issued'
            ? \App\Models\LocalOfflineActivationRequest::where('request_id', $offlineRequest->request_id)->value('status')
            : null);

        $stored = TablePlayInstallation::firstOrFail();
        $this->assertSame($offlineRequest->license_revision, $stored->license_revision);
        $this->assertNotEmpty($stored->license_content_hash);

        $this->travel(31)->days();
        $leaseDue = app(EntitlementService::class)->state();
        $this->assertSame('active', $leaseDue['status']);
        $this->assertFalse($leaseDue['verification_current']);
        $this->assertFalse($leaseDue['licensed']);
        $this->assertStringContainsString('verification lease', data_get($leaseDue, 'warnings.0.message'));
    }

    public function test_repeated_generation_reuses_and_redownloads_the_same_pending_request(): void
    {
        $first = $this->actingAs($this->admin)->post(route('admin.license.request'));
        $first->assertOk()->assertDownload();
        $record = \App\Models\LocalOfflineActivationRequest::firstOrFail();

        $second = $this->actingAs($this->admin)->post(route('admin.license.request'));
        $second->assertOk()->assertDownload();
        $download = $this->actingAs($this->admin)
            ->get(route('admin.license.request.download', $record));

        $download->assertOk()->assertDownload();
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame($first->getContent(), $download->getContent());
        $this->assertDatabaseCount('local_offline_activation_requests', 1);
    }

    public function test_tampered_request_is_rejected_before_cloud_registration(): void
    {
        $subscription = $this->cloudSubscription();
        $response = $this->actingAs($this->admin)->post(route('admin.license.request'));
        $document = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $document['payload']['device_name'] = 'Tampered server';

        $this->actingAs($this->superadmin)->post(route('superadmin.cloud.offline-requests.import'), [
            'subscription_id' => $subscription->id,
            'activation_request' => UploadedFile::fake()->createWithContent('tampered.tpr', json_encode($document, JSON_THROW_ON_ERROR)),
        ])->assertRedirect()->assertSessionHasErrors('activation_request');

        $this->assertDatabaseCount('cloud_offline_activation_requests', 0);
        $this->assertDatabaseCount('cloud_installations', 0);
    }

    public function test_offline_issue_is_idempotent_and_redownloads_exact_signed_file(): void
    {
        $subscription = $this->cloudSubscription();
        $requestResponse = $this->actingAs($this->admin)->post(route('admin.license.request'));
        $requestJson = $requestResponse->getContent();
        $payload = [
            'subscription_id' => $subscription->id,
            'activation_request' => UploadedFile::fake()->createWithContent('restaurant.tpr', $requestJson),
        ];
        $this->actingAs($this->superadmin)->post(route('superadmin.cloud.offline-requests.import'), $payload)
            ->assertSessionHasNoErrors();
        $record = CloudOfflineActivationRequest::firstOrFail();
        $firstJson = $this->actingAs($this->superadmin)
            ->get(route('superadmin.cloud.offline-requests.license', $record))->getContent();

        $this->actingAs($this->superadmin)->post(route('superadmin.cloud.offline-requests.import'), [
            'subscription_id' => $subscription->id,
            'activation_request' => UploadedFile::fake()->createWithContent('same-request.tpr', $requestJson),
        ])->assertSessionHasNoErrors();
        $secondJson = $this->actingAs($this->superadmin)
            ->get(route('superadmin.cloud.offline-requests.license', $record->fresh()))->getContent();

        $this->assertSame($firstJson, $secondJson);
        $this->assertDatabaseCount('cloud_offline_activation_requests', 1);
        $this->assertDatabaseCount('cloud_installations', 1);
    }

    public function test_only_correct_roles_can_exchange_offline_activation_files(): void
    {
        $counter = $this->user('counter');
        $subscription = $this->cloudSubscription();

        $this->actingAs($counter)->post(route('admin.license.request'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('superadmin.cloud.index'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('superadmin.cloud.offline-requests.import'), [
            'subscription_id' => $subscription->id,
        ])->assertForbidden();
    }

    public function test_subscription_installation_limit_is_enforced(): void
    {
        $subscription = $this->cloudSubscription();
        CloudInstallation::create([
            'cloud_subscription_id' => $subscription->id,
            'installation_uuid' => (string) Str::uuid(),
            'device_name' => 'Existing server',
            'device_fingerprint' => hash('sha256', 'existing-server'),
            'activation_token_hash' => hash('sha256', 'not-a-real-token'),
            'activation_method' => 'online',
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $requestJson = $this->actingAs($this->admin)->post(route('admin.license.request'))->getContent();

        $this->actingAs($this->superadmin)->post(route('superadmin.cloud.offline-requests.import'), [
            'subscription_id' => $subscription->id,
            'activation_request' => UploadedFile::fake()->createWithContent('second-server.tpr', $requestJson),
        ])->assertRedirect()->assertSessionHasErrors('subscription_id');

        $this->assertDatabaseCount('cloud_installations', 1);
        $this->assertSame('imported', CloudOfflineActivationRequest::firstOrFail()->status);
    }

    private function cloudSubscription(): CloudSubscription
    {
        $restaurant = CloudRestaurant::create([
            'restaurant_uuid' => (string) Str::uuid(),
            'name' => 'USB Only Restaurant',
            'status' => 'active',
        ]);
        $plan = CommercialPlan::where('slug', 'best')->firstOrFail();
        return CloudSubscription::create([
            'cloud_restaurant_id' => $restaurant->id,
            'commercial_plan_id' => $plan->id,
            'license_reference' => 'TP-'.Str::upper(Str::random(14)),
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addYear(),
            'grace_ends_at' => now()->addYear()->addDays($plan->grace_days),
            'max_installations' => 1,
            'license_revision' => 1,
        ]);
    }

    private function user(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => Str::headline($roleName)]);
        return User::create([
            'role_id' => $role->id,
            'name' => Str::headline($roleName),
            'username' => $roleName.Str::lower(Str::random(8)),
            'password' => 'password123',
            'is_active' => true,
        ]);
    }
}
