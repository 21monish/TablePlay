<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishInstallerTest extends TestCase
{
    public function test_it_refuses_a_wrong_checksum_and_publishes_an_exact_installer(): void
    {
        Storage::fake('updates');
        $directory = storage_path('framework/testing/installer-command');
        File::ensureDirectoryExists($directory);
        $file = $directory.DIRECTORY_SEPARATOR.'TablePlay-Setup.exe';
        File::put($file, 'trusted-installer');

        config([
            'app_updates.installer.disk' => 'updates',
            'app_updates.installer.path' => 'server-windows/stable/TablePlay-Setup.exe',
            'app_updates.installer.version' => '2.5.6',
            'app_updates.installer.sha256' => str_repeat('0', 64),
        ]);

        $this->artisan('tableplay:publish-installer', ['file' => $file])
            ->expectsOutputToContain('does not match')
            ->assertFailed();
        Storage::disk('updates')->assertMissing('server-windows/stable/TablePlay-Setup.exe');

        config(['app_updates.installer.sha256' => hash_file('sha256', $file)]);
        $this->artisan('tableplay:publish-installer', ['file' => $file])
            ->expectsOutputToContain('published successfully')
            ->assertSuccessful();
        Storage::disk('updates')->assertExists('server-windows/stable/TablePlay-Setup.exe');

        File::deleteDirectory($directory);
    }
}
