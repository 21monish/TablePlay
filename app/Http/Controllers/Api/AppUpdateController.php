<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AppUpdateController extends Controller
{
    public function check(Request $request, AppUpdateService $updates)
    {
        $data = $request->validate([
            'app' => ['required', Rule::in(['staff', 'customer'])],
            'platform' => ['required', Rule::in(['android', 'windows'])],
            'current_version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/'],
            'build_number' => ['nullable', 'integer', 'min:1'],
            'installation_uuid' => ['required', 'uuid'],
            'device_name' => ['required', 'string', 'max:100'],
            'channel' => ['nullable', Rule::in(['stable', 'beta', 'pilot'])],
        ]);
        $data['channel'] = $data['channel'] ?? 'stable';

        $target = $updates->targetName($data['app'], $data['platform']);
        abort_if($target === null, 422, 'This application is not available for the selected platform.');

        $release = $updates->latest($data['app'], $data['platform'], $data['channel']);
        $included = $release ? $updates->includedInRollout($release, $data['installation_uuid']) : false;
        $available = $release
            ? $included && $updates->updateAvailable($release, $data['current_version'], $data['build_number'] ?? null)
            : false;

        $updates->recordInstallation($request, $data, $available);

        if (! $release) {
            return response()->json([
                'update_available' => false,
                'latest_version' => null,
                'message' => 'No published update is available for this application.',
            ]);
        }

        return response()->json([
            'update_available' => $available,
            'latest_version' => $release->version,
            'latest_build_number' => $release->build_number,
            'mandatory' => $available && $updates->mandatory($release, $data['current_version']),
            'minimum_supported_version' => $release->minimum_supported_version,
            'release_notes' => $release->release_notes,
            'channel' => $data['channel'],
            'rollout_pending' => ! $included,
            'download_url' => route('api.app-updates.download', array_filter(['target' => $target, 'channel' => $data['channel'] === 'stable' ? null : $data['channel']])),
            'sha256' => $release->sha256,
            'file_size' => $release->file_size,
            'published_at' => $release->published_at?->toISOString(),
        ]);
    }

    public function download(Request $request, string $target, AppUpdateService $updates)
    {
        $definition = $updates->target($target);
        abort_if($definition === null, 404);

        $channel = $request->validate(['channel' => ['nullable', Rule::in(['stable', 'beta', 'pilot'])]])['channel'] ?? 'stable';
        $release = $updates->latest($definition['app'], $definition['platform'], $channel);
        abort_if($release === null || ! Storage::disk('updates')->exists($release->file_path), 404);

        return response()->download(
            Storage::disk('updates')->path($release->file_path),
            "$target.{$definition['extension']}",
            [
                'Content-Type' => $release->mime_type ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'X-Checksum-SHA256' => $release->sha256,
                'X-App-Version' => $release->version,
            ],
        );
    }
}
