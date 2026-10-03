<?php

namespace Tests\Feature;

use App\Services\PackageInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class PackageInspectorTest extends TestCase
{
    public function test_current_debug_android_build_cannot_be_published(): void
    {
        $apk = base_path('staff_app/build/app/outputs/flutter-apk/app-debug.apk');
        if (! is_file($apk)) {
            $this->markTestSkipped('Build the Staff debug APK to run the real signing-gate check.');
        }

        $file = new UploadedFile($apk, 'staff-debug.apk', null, null, true);

        try {
            app(PackageInspector::class)->inspect($file, 'staff-android', '1.1.0', 2);
            $this->fail('The debug-signed APK was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Debug-signed APKs cannot be published. Build with the protected TablePlay release keystore.',
                $exception->errors()['package'][0],
            );
        }
    }

    public function test_complete_windows_release_zip_passes_structure_validation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tableplay-package-').'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach (['tableplay_staff.exe', 'tableplay_updater.exe', 'flutter_windows.dll', 'data/app.so'] as $entry) {
            $zip->addFromString($entry, 'test');
        }
        $zip->close();

        try {
            $result = app(PackageInspector::class)->inspect(
                new UploadedFile($path, 'staff-windows.zip', null, null, true),
                'staff-windows',
                '1.2.0',
                null,
            );

            $this->assertSame('com.tableplay.staff.windows', $result['package_identifier']);
            $this->assertNotNull($result['verified_at']);
        } finally {
            @unlink($path);
        }
    }

    public function test_built_windows_release_passes_the_real_package_gate(): void
    {
        $package = base_path('dist/TablePlay-Staff-Windows-x64-v1.9.2.zip');
        if (! is_file($package)) {
            $this->markTestSkipped('Build and package the Staff Windows release to run this check.');
        }

        $result = app(PackageInspector::class)->inspect(
            new UploadedFile($package, 'staff-windows.zip', null, null, true),
            'staff-windows',
            '1.9.2',
            null,
        );

        $this->assertSame('com.tableplay.staff.windows', $result['package_identifier']);
    }

    public function test_built_signed_android_releases_pass_the_real_package_gate(): void
    {
        $packages = [
            'staff-android' => [
                base_path('dist/TablePlay-Staff-Android-arm64-v1.9.2-release.apk'),
                'com.tableplay.tableplay_staff',
                '1.9.2',
                2014,
            ],
            'customer-android' => [
                base_path('dist/TablePlay-Customer-Android-v1.12.1-release.apk'),
                'com.tableplay.tableplay_tablet',
                '1.12.1',
                2015,
            ],
        ];

        foreach ($packages as [$path]) {
            if (! is_file($path)) {
                $this->markTestSkipped('Build both signed Android releases to run this check.');
            }
        }

        $certificates = [];
        foreach ($packages as $target => [$path, $applicationId, $version, $build]) {
            $result = app(PackageInspector::class)->inspect(
                new UploadedFile($path, basename($path), null, null, true),
                $target,
                $version,
                $build,
            );

            $this->assertSame($applicationId, $result['package_identifier']);
            $this->assertSame(64, strlen($result['signing_certificate_sha256']));
            $certificates[] = $result['signing_certificate_sha256'];
        }

        $this->assertSame($certificates[0], $certificates[1]);
    }
}
