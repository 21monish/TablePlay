<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use ZipArchive;

class PackageInspector
{
    public function inspect(
        UploadedFile $file,
        string $target,
        string $version,
        ?int $buildNumber,
    ): array {
        return str_ends_with($target, '-android')
            ? $this->inspectAndroid($file, $target, $version, $buildNumber)
            : $this->inspectWindows($file);
    }

    private function inspectAndroid(
        UploadedFile $file,
        string $target,
        string $version,
        ?int $buildNumber,
    ): array {
        [$signerCommand, $aapt] = $this->androidTools();

        $signature = new Process([...$signerCommand, 'verify', '--print-certs', $file->getRealPath()]);
        $signature->setTimeout(90);
        $signature->run();
        if (! $signature->isSuccessful()) {
            throw ValidationException::withMessages([
                'package' => 'The Android package is unsigned or its signature is invalid.',
            ]);
        }

        $signatureOutput = $signature->getOutput().$signature->getErrorOutput();
        if (stripos($signatureOutput, 'Android Debug') !== false) {
            throw ValidationException::withMessages([
                'package' => 'Debug-signed APKs cannot be published. Build with the protected TablePlay release keystore.',
            ]);
        }
        preg_match('/certificate SHA-256 digest:\s*([0-9a-f:]+)/i', $signatureOutput, $certificate);
        $certificateSha256 = strtolower(str_replace(':', '', $certificate[1] ?? ''));
        if (strlen($certificateSha256) !== 64) {
            throw ValidationException::withMessages([
                'package' => 'The APK signing certificate could not be verified.',
            ]);
        }

        $manifest = new Process([$aapt, 'dump', 'badging', $file->getRealPath()]);
        $manifest->setTimeout(90);
        $manifest->run();
        if (! $manifest->isSuccessful()
            || ! preg_match("/package: name='([^']+)' versionCode='([^']+)' versionName='([^']+)'/", $manifest->getOutput(), $details)) {
            throw ValidationException::withMessages([
                'package' => 'The APK manifest could not be inspected.',
            ]);
        }

        $expectedPackage = config("app_updates.package_identifiers.$target");
        if ($details[1] !== $expectedPackage) {
            throw ValidationException::withMessages([
                'package' => "Expected application ID $expectedPackage, but the APK contains {$details[1]}.",
            ]);
        }
        if ($details[3] !== $version || (int) $details[2] !== $buildNumber) {
            throw ValidationException::withMessages([
                'package' => "The APK contains version {$details[3]} build {$details[2]}; make the form match the package.",
            ]);
        }

        return [
            'package_identifier' => $details[1],
            'signing_certificate_sha256' => $certificateSha256,
            'verified_at' => now(),
        ];
    }

    private function inspectWindows(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages([
                'package' => 'The Windows package is not a readable ZIP archive.',
            ]);
        }

        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = str_replace('\\', '/', $zip->getNameIndex($index));
                if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    throw ValidationException::withMessages([
                        'package' => 'The Windows ZIP contains an unsafe file path.',
                    ]);
                }
                $entries[] = strtolower(rtrim($name, '/'));
            }

            foreach (['tableplay_staff.exe', 'tableplay_updater.exe', 'flutter_windows.dll', 'data/app.so'] as $required) {
                if (! in_array($required, $entries, true)) {
                    throw ValidationException::withMessages([
                        'package' => "The Windows ZIP is incomplete: $required is missing.",
                    ]);
                }
            }
        } finally {
            $zip->close();
        }

        return [
            'package_identifier' => 'com.tableplay.staff.windows',
            'signing_certificate_sha256' => null,
            'verified_at' => now(),
        ];
    }

    private function androidTools(): array
    {
        $sdk = rtrim((string) config('app_updates.android_sdk'), '\\/');
        $directories = glob($sdk.DIRECTORY_SEPARATOR.'build-tools'.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];
        usort($directories, fn (string $a, string $b) => version_compare(basename($b), basename($a)));
        $directory = $directories[0] ?? null;
        $apksigner = $directory ? $directory.DIRECTORY_SEPARATOR.'apksigner.bat' : null;
        $apksignerJar = $directory ? $directory.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'apksigner.jar' : null;
        $aapt = $directory ? $directory.DIRECTORY_SEPARATOR.'aapt.exe' : null;

        if (! $directory || ! $aapt || ! is_file($aapt)) {
            throw ValidationException::withMessages([
                'package' => 'Android SDK build tools are unavailable on the local update server.',
            ]);
        }

        $java = (string) config('app_updates.java');
        if ($java !== '' && is_file($java) && $apksignerJar && is_file($apksignerJar)) {
            return [[$java, '-jar', $apksignerJar], $aapt];
        }

        if ($apksigner && is_file($apksigner)) {
            return [[$apksigner], $aapt];
        }

        throw ValidationException::withMessages([
            'package' => 'The Android signing verifier is unavailable on the local update server.',
        ]);
    }
}
