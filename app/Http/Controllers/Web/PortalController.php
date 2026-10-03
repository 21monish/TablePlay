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
            'staffRelease' => $staffRelease,
            'staffAvailable' => $staffAvailable,
            'customerRelease' => $customerRelease,
            'customerAvailable' => $customerAvailable,
            'serverAddress' => rtrim($request->getSchemeAndHttpHost(), '/'),
        ]);
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
