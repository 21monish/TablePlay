<?php

namespace App\Services;

use App\Models\AppInstallation;
use App\Models\AppRelease;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;

class AppUpdateService
{
    public const TARGETS = [
        'staff-android' => ['app' => 'staff', 'platform' => 'android', 'extension' => 'apk'],
        'customer-android' => ['app' => 'customer', 'platform' => 'android', 'extension' => 'apk'],
        'staff-windows' => ['app' => 'staff', 'platform' => 'windows', 'extension' => 'zip'],
    ];

    public function target(string $target): ?array
    {
        return self::TARGETS[$target] ?? null;
    }

    public function targetName(string $app, string $platform): ?string
    {
        foreach (self::TARGETS as $name => $target) {
            if ($target['app'] === $app && $target['platform'] === $platform) {
                return $name;
            }
        }

        return null;
    }

    public function latest(string $app, string $platform, string $channel = 'stable'): ?AppRelease
    {
        return AppRelease::query()
            ->where(compact('app', 'platform', 'channel'))
            ->where('is_published', true)
            ->get()
            ->sort(fn (AppRelease $a, AppRelease $b) => version_compare($b->version, $a->version))
            ->first();
    }

    public function includedInRollout(AppRelease $release, string $installationUuid): bool
    {
        $percentage = max(0, min(100, (int) ($release->rollout_percentage ?? 100)));
        if ($percentage >= 100) return true;
        if ($percentage <= 0) return false;

        $bucket = hexdec(substr(hash('sha256', $release->id.'|'.$installationUuid), 0, 8)) % 100;
        return $bucket < $percentage;
    }

    public function updateAvailable(AppRelease $release, string $version, ?int $buildNumber): bool
    {
        $comparison = version_compare($release->version, $version);

        return $comparison > 0 || (
            $comparison === 0
            && $release->build_number !== null
            && $buildNumber !== null
            && $release->build_number > $buildNumber
        );
    }

    public function mandatory(AppRelease $release, string $version): bool
    {
        return $release->mandatory || (
            $release->minimum_supported_version
            && version_compare($version, $release->minimum_supported_version, '<')
        );
    }

    public function recordInstallation(
        Request $request,
        array $data,
        bool $updateAvailable,
    ): AppInstallation {
        $actor = $request->user('sanctum');
        $headerUuid = (string) $request->header('X-Device-UUID');
        $device = $actor instanceof Device
            && $headerUuid !== ''
            && hash_equals($actor->device_uuid, $headerUuid)
                ? $actor
                : null;

        if ($device && $data['app'] === 'customer') {
            $device->update([
                'app_version' => $data['current_version'],
                'last_seen_at' => now(),
                'ip_address' => $request->ip(),
            ]);
        }

        return AppInstallation::updateOrCreate(
            [
                'app' => $data['app'],
                'platform' => $data['platform'],
                'installation_uuid' => $data['installation_uuid'],
            ],
            [
                'device_name' => $data['device_name'],
                'channel' => $data['channel'] ?? 'stable',
                'current_version' => $data['current_version'],
                'build_number' => $data['build_number'] ?? null,
                'device_id' => $device?->id,
                'user_id' => $actor instanceof User ? $actor->id : null,
                'ip_address' => $request->ip(),
                'update_available' => $updateAvailable,
                'last_checked_at' => now(),
            ],
        );
    }
}
