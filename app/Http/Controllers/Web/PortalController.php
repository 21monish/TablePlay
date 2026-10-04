<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use App\Services\AppUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PortalController extends Controller
{
    public function __invoke(Request $request, AppUpdateService $updates)
    {
        [$staffRelease, $staffAvailable] = $this->publishedRelease($updates, 'staff', 'windows');
        [$customerRelease, $customerAvailable] = $this->publishedRelease($updates, 'customer', 'android');

        return view('portal.index', [
            'installer' => $this->installerRelease(),
            'staffRelease' => $staffRelease,
            'staffAvailable' => $staffAvailable,
            'customerRelease' => $customerRelease,
            'customerAvailable' => $customerAvailable,
            'serverAddress' => rtrim($request->getSchemeAndHttpHost(), '/'),
        ]);
    }

    private function installerRelease(): array
    {
        $url = trim((string) config('app_updates.installer.url'));
        $version = trim((string) config('app_updates.installer.version'));
        $size = (int) config('app_updates.installer.size', 0);
        $sha256 = strtolower(trim((string) config('app_updates.installer.sha256')));
        $parts = $url === '' ? false : parse_url($url);
        $trustedUrl = is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), [
                'github.com',
                'objects.githubusercontent.com',
            ], true);

        return [
            'available' => $trustedUrl
                && $version !== ''
                && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1,
            'version' => $version,
            'size' => $size,
            'sha256' => $sha256,
        ];
    }

    private function publishedRelease(AppUpdateService $updates, string $app, string $platform): array
    {
        try {
            $release = $updates->latest($app, $platform);

            return [
                $release,
                $release instanceof AppRelease
                    && Storage::disk('updates')->exists($release->file_path),
            ];
        } catch (Throwable) {
            // The public portal must remain available while installation or database recovery is in progress.
            return [null, false];
        }
    }
}
