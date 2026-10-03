<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSetting;
use Illuminate\Support\Facades\Storage;

class BrandAssetController extends Controller
{
    public function favicon()
    {
        $settings = RestaurantSetting::query()->first();
        $storedPath = $settings?->favicon_path;

        if ($storedPath && str_starts_with($storedPath, '/storage/')) {
            $relativePath = substr($storedPath, 9);
            if (Storage::disk('public')->exists($relativePath)) {
                return response()->file(Storage::disk('public')->path($relativePath), [
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
            }
        }

        return response()->file(public_path('favicon.svg'), [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
