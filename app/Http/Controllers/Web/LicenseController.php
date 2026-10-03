<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{LicenseSyncLog, LocalOfflineActivationRequest};
use App\Services\{EntitlementService, LocalLicenseService, OfflineActivationRequestService};
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    public function index(EntitlementService $entitlements, LocalLicenseService $licenses)
    {
        $installation = $licenses->installation();
        return view('admin.license', [
            'entitlements' => $entitlements->state(),
            'installation' => $installation,
            'syncLogs' => LicenseSyncLog::where('installation_uuid', $installation->installation_uuid)->latest()->limit(20)->get(),
            'offlineRequests' => LocalOfflineActivationRequest::where('installation_uuid', $installation->installation_uuid)->latest()->limit(5)->get(),
        ]);
    }

    public function activate(Request $request, LocalLicenseService $licenses)
    {
        $data = $request->validate(['cloud_url' => ['required', 'url', 'max:500'], 'license_key' => ['required', 'string', 'max:100']]);
        $subscription = $licenses->activate($data['license_key'], $data['cloud_url']);
        return back()->with('status', "TablePlay {$subscription->plan->name} activated and verified.");
    }

    public function sync(LocalLicenseService $licenses)
    {
        try { $subscription = $licenses->sync(); return back()->with('status', "Licence synchronized: {$subscription->plan->name}."); }
        catch (\Throwable $error) { return back()->with('error', $error->getMessage()); }
    }

    public function import(Request $request, LocalLicenseService $licenses)
    {
        $request->validate(['license_file' => ['required', 'file', 'max:64', 'extensions:tpl,json']]);
        $subscription = $licenses->import((string) file_get_contents($request->file('license_file')->getRealPath()));
        return back()->with('status', "Offline {$subscription->plan->name} licence imported and verified.");
    }

    public function generateRequest(LocalLicenseService $licenses, OfflineActivationRequestService $requests)
    {
        $installation = $licenses->installation();
        $document = $requests->create($installation, $licenses->serverVersion());
        return $this->requestDownload($document, $installation->installation_uuid);
    }

    public function downloadRequest(
        LocalOfflineActivationRequest $offlineRequest,
        LocalLicenseService $licenses,
        OfflineActivationRequestService $requests,
    )
    {
        $installation = $licenses->installation();
        abort_unless(hash_equals($installation->installation_uuid, $offlineRequest->installation_uuid), 404);
        $document = $offlineRequest->request_document;
        $payload = is_array($document) ? $requests->validate($document) : [];
        abort_unless(hash_equals((string) $offlineRequest->request_hash, (string) ($payload['request_hash'] ?? '')), 409);

        return $this->requestDownload($document, $installation->installation_uuid);
    }

    private function requestDownload(array $document, string $installationUuid)
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $requestId = (string) data_get($document, 'payload.request_id', 'request');
        $filename = 'tableplay-activation-'.$installationUuid.'-'.$requestId.'.tpr';

        return response($json, 200, [
            'Content-Type' => 'application/vnd.tableplay.activation-request+json',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
