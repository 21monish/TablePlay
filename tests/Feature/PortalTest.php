<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_portal_is_available_without_login(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('TablePlay Portal')
            ->assertSee('Staff Desktop')
            ->assertSee('Customer Table App')
            ->assertSee('Staff sign in')
            ->assertSee(route('privacy'), false)
            ->assertSee(route('terms'), false)
            ->assertSee('Included with TablePlay Setup')
            ->assertDontSee('Not yet published');
    }

    public function test_public_legal_pages_are_available_without_login(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('gmail.send');

        $this->get('/terms')
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Plans, trials, and licences');
    }

    public function test_portal_exposes_only_published_packages_that_exist_on_disk(): void
    {
        Storage::fake('updates');
        Storage::disk('updates')->put('staff/1.3.1/staff.zip', 'staff-package');
        Storage::disk('updates')->put('customer/1.3.0/customer.apk', 'customer-package');

        $this->release('staff', 'windows', '1.3.1', 'staff/1.3.1/staff.zip', 'staff.zip');
        $this->release('customer', 'android', '1.3.0', 'customer/1.3.0/customer.apk', 'customer.apk', 4);

        $this->get('/')
            ->assertOk()
            ->assertSee('Version 1.3.1')
            ->assertSee('Version 1.3.0')
            ->assertSee('/api/v1/app-updates/download/staff-windows', false)
            ->assertSee('/api/v1/app-updates/download/customer-android', false);
    }

    public function test_portal_hides_a_published_database_record_when_its_file_is_missing(): void
    {
        Storage::fake('updates');
        $this->release('staff', 'windows', '9.9.9', 'missing/staff.zip', 'missing.zip');

        $this->get('/')
            ->assertOk()
            ->assertSee('Version 9.9.9')
            ->assertSee('Package temporarily unavailable')
            ->assertDontSee('/api/v1/app-updates/download/staff-windows', false);
    }

    public function test_portal_presents_the_verified_setup_release_as_the_distribution_entry_point(): void
    {
        config([
            'app_updates.installer.url' => 'https://github.com/21monish/TablePlay/releases/download/v2.5.6/TablePlay-Setup-v2.5.6.exe',
            'app_updates.installer.version' => '2.5.6',
            'app_updates.installer.size' => 466790947,
            'app_updates.installer.sha256' => str_repeat('a', 64),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('TablePlay Setup 2.5.6')
            ->assertSee('Published and verified')
            ->assertSee('445.2 MB')
            ->assertSee(route('trial.create'), false);
    }

    private function release(
        string $app,
        string $platform,
        string $version,
        string $filePath,
        string $originalFilename,
        ?int $buildNumber = null,
    ): AppRelease {
        return AppRelease::create([
            'app' => $app,
            'platform' => $platform,
            'version' => $version,
            'build_number' => $buildNumber,
            'file_path' => $filePath,
            'original_filename' => $originalFilename,
            'mime_type' => $platform === 'android' ? 'application/vnd.android.package-archive' : 'application/zip',
            'file_size' => 13,
            'sha256' => str_repeat('a', 64),
            'verified_at' => now(),
            'is_published' => true,
            'published_at' => now(),
        ]);
    }
}
