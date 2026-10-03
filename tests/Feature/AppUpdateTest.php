<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use App\Services\PackageInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class AppUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('updates');
    }

    public function test_check_without_a_published_release_reports_the_installation(): void
    {
        $this->getJson('/api/v1/app-updates/check?'.http_build_query([
            'app' => 'customer',
            'platform' => 'android',
            'current_version' => '1.1.0',
            'build_number' => 2,
            'installation_uuid' => '3c2f171a-7318-41fd-815d-30b392636d55',
            'device_name' => 'Table 1 tablet',
        ]))->assertOk()
            ->assertJsonPath('update_available', false)
            ->assertJsonPath('latest_version', null);

        $this->assertDatabaseHas('app_installations', [
            'app' => 'customer',
            'platform' => 'android',
            'current_version' => '1.1.0',
            'build_number' => 2,
            'update_available' => false,
        ]);
    }

    public function test_authenticated_customer_check_links_only_its_own_device_report(): void
    {
        $device = Device::create([
            'device_uuid' => 'fefaf2bc-779e-43f6-8b32-c2f9fb047a89',
            'device_name' => 'Table 4 tablet',
            'device_type' => 'tablet',
            'is_active' => true,
        ]);
        $token = $device->createToken('tablet')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Device-UUID', $device->device_uuid)
            ->getJson('/api/v1/app-updates/check?'.http_build_query([
                'app' => 'customer',
                'platform' => 'android',
                'current_version' => '1.2.0',
                'build_number' => 3,
                'installation_uuid' => '9a93d8b6-b512-4699-992c-42688782e740',
                'device_name' => 'Table 4 tablet',
            ]))->assertOk();

        $this->assertDatabaseHas('app_installations', [
            'device_id' => $device->id,
            'current_version' => '1.2.0',
        ]);
        $this->assertSame('1.2.0', $device->fresh()->app_version);
    }

    public function test_check_returns_the_latest_published_release_and_mandatory_policy(): void
    {
        $this->release('1.9.0', published: true);
        $latest = $this->release('1.10.0', published: true, minimum: '1.5.0');

        $this->getJson('/api/v1/app-updates/check?'.http_build_query([
            'app' => 'staff',
            'platform' => 'android',
            'current_version' => '1.4.0',
            'build_number' => 4,
            'installation_uuid' => '1e47d87e-b734-4848-9137-997ad469bb47',
            'device_name' => 'Counter phone',
        ]))->assertOk()
            ->assertJsonPath('update_available', true)
            ->assertJsonPath('latest_version', '1.10.0')
            ->assertJsonPath('latest_build_number', $latest->build_number)
            ->assertJsonPath('mandatory', true)
            ->assertJsonPath('sha256', $latest->sha256)
            ->assertJsonPath('download_url', url('/api/v1/app-updates/download/staff-android'));

        $this->assertDatabaseHas('app_installations', ['update_available' => true]);
    }

    public function test_download_serves_only_the_latest_published_package_with_checksum_headers(): void
    {
        $release = $this->release('1.2.0', published: true);
        Storage::disk('updates')->put($release->file_path, 'signed-apk-contents');

        $this->get('/api/v1/app-updates/download/staff-android')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=staff-android.apk')
            ->assertHeader('x-checksum-sha256', $release->sha256)
            ->assertHeader('x-app-version', '1.2.0');

        $this->get('/api/v1/app-updates/download/customer-windows')->assertNotFound();
    }

    public function test_pilot_release_is_isolated_from_stable_devices(): void
    {
        $stable = $this->release('2.0.0', published: true);
        $pilot = $this->release('2.1.0', published: true);
        $pilot->update(['channel' => 'pilot', 'rollout_percentage' => 100]);
        Storage::disk('updates')->put($stable->file_path, 'signed-apk-contents');
        Storage::disk('updates')->put($pilot->file_path, 'signed-apk-contents');

        $base = [
            'app' => 'staff', 'platform' => 'android', 'current_version' => '1.9.0', 'build_number' => 9,
            'installation_uuid' => 'd9c4c944-37f5-4e52-a1b7-4db95153b660', 'device_name' => 'Pilot counter',
        ];
        $this->getJson('/api/v1/app-updates/check?'.http_build_query($base))
            ->assertOk()->assertJsonPath('latest_version', '2.0.0')->assertJsonPath('channel', 'stable');
        $this->getJson('/api/v1/app-updates/check?'.http_build_query($base + ['channel' => 'pilot']))
            ->assertOk()->assertJsonPath('latest_version', '2.1.0')->assertJsonPath('channel', 'pilot')
            ->assertJsonPath('download_url', url('/api/v1/app-updates/download/staff-android?channel=pilot'));

        $this->assertDatabaseHas('app_installations', ['installation_uuid' => $base['installation_uuid'], 'channel' => 'pilot']);
    }

    public function test_admin_can_upload_and_publish_a_package_with_a_server_generated_checksum(): void
    {
        $this->mock(PackageInspector::class, function (MockInterface $mock) {
            $mock->shouldReceive('inspect')->once()->andReturn([
                'package_identifier' => 'com.tableplay.tableplay_staff',
                'signing_certificate_sha256' => str_repeat('a', 64),
                'verified_at' => now(),
            ]);
        });

        $admin = $this->user('admin');
        $package = UploadedFile::fake()->create(
            'tableplay-staff.apk',
            32,
            'application/vnd.android.package-archive',
        );

        $this->actingAs($admin)->post('/admin/app-updates', [
            'target' => 'staff-android',
            'version' => '1.2.0',
            'build_number' => 3,
            'minimum_supported_version' => '1.1.0',
            'release_notes' => 'Local update delivery.',
            'package' => $package,
            'publish' => '1',
        ])->assertRedirect();

        $release = AppRelease::firstOrFail();
        $this->assertTrue($release->is_published);
        $this->assertSame(64, strlen($release->sha256));
        $this->assertNotNull($release->verified_at);
        Storage::disk('updates')->assertExists($release->file_path);
    }

    public function test_non_admin_cannot_open_or_publish_from_the_update_panel(): void
    {
        $counter = $this->user('counter');

        $this->actingAs($counter)->get('/admin/app-updates')->assertForbidden();
        $this->actingAs($counter)->post('/admin/app-updates', [])->assertForbidden();
    }

    public function test_android_build_numbers_must_increase(): void
    {
        $this->release('1.2.0', published: false);
        $admin = $this->user('admin');

        $this->actingAs($admin)->post('/admin/app-updates', [
            'target' => 'staff-android',
            'version' => '1.3.0',
            'build_number' => 10,
            'package' => UploadedFile::fake()->create('staff.apk', 10),
        ])->assertSessionHasErrors('build_number');
    }

    public function test_unverified_drafts_cannot_be_published(): void
    {
        $release = $this->release('1.2.0', published: false);
        Storage::disk('updates')->put($release->file_path, 'package');

        $this->actingAs($this->user('admin'))
            ->post("/admin/app-updates/{$release->id}/publish")
            ->assertStatus(422);
    }

    public function test_android_releases_must_keep_the_same_signing_certificate(): void
    {
        $existing = $this->release('1.2.0', published: false);
        $existing->update([
            'signing_certificate_sha256' => str_repeat('a', 64),
            'verified_at' => now(),
        ]);

        $this->mock(PackageInspector::class, function (MockInterface $mock) {
            $mock->shouldReceive('inspect')->once()->andReturn([
                'package_identifier' => 'com.tableplay.tableplay_staff',
                'signing_certificate_sha256' => str_repeat('b', 64),
                'verified_at' => now(),
            ]);
        });

        $this->actingAs($this->user('admin'))->post('/admin/app-updates', [
            'target' => 'staff-android',
            'version' => '1.3.0',
            'build_number' => 11,
            'package' => UploadedFile::fake()->create('staff.apk', 10),
        ])->assertSessionHasErrors('package');

        $this->assertDatabaseCount('app_releases', 1);
    }

    public function test_admin_cannot_publish_a_release_older_than_the_current_channel(): void
    {
        $current = $this->release('2.0.0', published: true);
        $current->update(['verified_at' => now()]);
        $older = $this->release('1.8.0', published: false);
        $older->update(['verified_at' => now()]);
        Storage::disk('updates')->put($older->file_path, 'package');

        $this->actingAs($this->user('admin'))
            ->post("/admin/app-updates/{$older->id}/publish")
            ->assertStatus(422);

        $this->assertTrue($current->fresh()->is_published);
        $this->assertFalse($older->fresh()->is_published);
    }

    private function release(
        string $version,
        bool $published,
        ?string $minimum = null,
    ): AppRelease {
        return AppRelease::create([
            'app' => 'staff',
            'platform' => 'android',
            'version' => $version,
            'build_number' => 10,
            'release_notes' => 'Test release',
            'mandatory' => false,
            'minimum_supported_version' => $minimum,
            'file_path' => "staff-android/$version/package.apk",
            'original_filename' => 'staff-android.apk',
            'mime_type' => 'application/vnd.android.package-archive',
            'file_size' => 19,
            'sha256' => hash('sha256', 'signed-apk-contents'),
            'is_published' => $published,
            'published_at' => $published ? now() : null,
        ]);
    }

    private function user(string $roleName): User
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['display_name' => ucfirst($roleName)],
        );

        return User::create([
            'role_id' => $role->id,
            'name' => ucfirst($roleName),
            'username' => $roleName,
            'password' => 'secret-password',
            'is_active' => true,
        ]);
    }
}
