<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CloudLicenseService;
use Illuminate\Http\Request;

class CloudLicenseController extends Controller
{
    public function activate(Request $request, CloudLicenseService $licenses)
    {
        $this->ensureCloud();
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:100'], 'installation_uuid' => ['required', 'uuid'],
            'device_name' => ['required', 'string', 'max:150'], 'device_fingerprint' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'server_version' => ['nullable', 'string', 'max:40'],
        ]);
        return response()->json($licenses->activate($data, $request->ip()));
    }

    public function sync(Request $request, CloudLicenseService $licenses)
    {
        $this->ensureCloud();
        $data = $request->validate([
            'installation_uuid' => ['required', 'uuid'],
            'device_fingerprint' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'server_version' => ['nullable', 'string', 'max:40'],
        ]);
        $token = $request->bearerToken();
        abort_if(blank($token), 401, 'Installation token is required.');
        return response()->json($licenses->sync($data['installation_uuid'], $token, $data, $request->ip()));
    }

    private function ensureCloud(): void
    {
        abort_unless(config('tableplay.mode') === 'cloud' || app()->environment(['local', 'testing']), 404);
    }
}
