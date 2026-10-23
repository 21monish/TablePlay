<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{DiningTable, LicenseSyncLog};
use App\Services\{AuditService, EntitlementService, LocalLicenseService, LocalNetworkService, OfflineActivationRequestService, SecurePairingService, SetupWizardService};
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminExperienceController extends Controller
{
    public function setup(Request $request, SetupWizardService $wizard, LocalNetworkService $network, EntitlementService $entitlements): array
    {
        $snapshot = $wizard->snapshot();
        try {
            $connection = $network->connection($request);
            $networkWarning = null;
        } catch (ValidationException $error) {
            $connection = null;
            $networkWarning = collect($error->errors())->flatten()->first();
        }

        return [
            'entitlements' => $entitlements->state(),
            'steps' => $snapshot['steps'],
            'completed' => $snapshot['completed'],
            'total' => $snapshot['total'],
            'percent' => $snapshot['percent'],
            'ready' => $snapshot['ready'],
            'connection' => $connection,
            'network_warning' => $networkWarning,
            'releases' => $snapshot['releases']->values()->map(fn ($release) => $release->only(['app', 'platform', 'version', 'build_number'])),
        ];
    }

    public function staffConnection(Request $request, LocalNetworkService $network): array
    {
        return $network->staffPayload($request);
    }

    public function pairing(Request $request, DiningTable $table, SecurePairingService $pairing, LocalNetworkService $network, AuditService $audit)
    {
        $payload = $pairing->issue($table, $request->user()->id, $network->connection($request));
        // Never include the one-time credential in audit history.
        $audit->record($request, 'pairing.qr.issued.mobile', $table, null, ['table_code' => $table->table_code, 'expires_at' => $payload['expires_at']]);

        return response()->json($payload, 201)->header('Cache-Control', 'no-store, private');
    }

    public function license(LocalLicenseService $licenses, EntitlementService $entitlements): array
    {
        $installation = $licenses->installation();

        return [
            'entitlements' => $entitlements->state(),
            'installation' => $installation->only(['installation_uuid', 'cloud_url', 'last_sync_at', 'last_sync_error']) + [
                'online_activated' => filled($installation->activation_token),
            ],
            'sync_logs' => LicenseSyncLog::where('installation_uuid', $installation->installation_uuid)
                ->latest()->limit(20)->get(['event', 'status', 'created_at']),
        ];
    }

    public function activate(Request $request, LocalLicenseService $licenses)
    {
        $data = $request->validate([
            'cloud_url' => ['required', 'url:http,https', 'max:500'],
            'license_key' => ['required', 'string', 'max:100'],
        ]);
        $licenses->activate(trim($data['license_key']), rtrim($data['cloud_url'], '/'));

        return response()->json(['message' => 'Licence activated and signature verified.']);
    }

    public function sync(LocalLicenseService $licenses)
    {
        try {
            $licenses->sync();
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['license' => 'Licence synchronization could not complete. Check the Cloud address, internet connection and licence status. Your existing licence has not been replaced.']);
        }

        return response()->json(['message' => 'Licence synchronized and verified.']);
    }

    public function import(Request $request, LocalLicenseService $licenses)
    {
        $request->validate(['license_file' => ['required', 'file', 'max:64', 'extensions:tpl,json']]);
        $licenses->import((string) file_get_contents($request->file('license_file')->getRealPath()));

        return response()->json(['message' => 'Offline licence imported and signature verified.']);
    }

    public function activationRequest(LocalLicenseService $licenses, OfflineActivationRequestService $requests)
    {
        $installation = $licenses->installation();
        $document = $requests->create($installation, $licenses->serverVersion());
        $filename = 'tableplay-activation-'.$installation->installation_uuid.'-'.data_get($document, 'payload.request_id').'.tpr';

        return response()->json(compact('document', 'filename'))->header('Cache-Control', 'no-store, private');
    }
}
