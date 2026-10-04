<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use App\Models\CloudLicenseKey;
use App\Models\CloudSubscriptionEvent;
use App\Services\AppUpdateService;
use App\Services\AuditService;
use App\Services\CloudLicenseService;
use App\Services\TrialProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RestaurantAccountController extends Controller
{
    public function index(
        Request $request,
        TrialProvisioningService $trials,
        AppUpdateService $updates,
    ): View {
        $subscription = $trials->provision($request->user());

        return view('restaurant-account.index', [
            'restaurant' => $request->user()->cloudRestaurant,
            'subscription' => $subscription->loadMissing('plan', 'installations'),
            'installer' => $this->installerDetails(),
            'appDownloads' => collect([
                $this->publishedDownload($updates, 'staff', 'windows', 'Staff Desktop', 'staff-windows'),
                $this->publishedDownload($updates, 'staff', 'android', 'Staff Android', 'staff-android'),
                $this->publishedDownload($updates, 'customer', 'android', 'Customer Tablet', 'customer-android'),
            ]),
        ]);
    }

    public function downloadInstaller(): StreamedResponse
    {
        $installer = $this->installerDetails();
        abort_unless($installer['available'], 404, 'The verified TablePlay Setup package has not been published yet.');

        $disk = Storage::disk($installer['disk']);
        $stream = $disk->readStream($installer['path']);
        abort_if($stream === false, 404);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 'TablePlay-Setup-v'.$installer['version'].'.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'Content-Length' => (string) $installer['file_size'],
            'Cache-Control' => 'private, no-store',
            'X-Checksum-SHA256' => $installer['sha256'],
            'X-App-Version' => $installer['version'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function issueActivationKey(
        Request $request,
        TrialProvisioningService $trials,
        CloudLicenseService $licenses,
        AuditService $audit,
    ): RedirectResponse {
        $subscription = $trials->provision($request->user());

        $plainKey = DB::transaction(function () use ($subscription, $licenses, $request): string {
            CloudLicenseKey::query()
                ->where('cloud_subscription_id', $subscription->id)
                ->where('is_active', true)
                ->whereNull('last_used_at')
                ->update(['is_active' => false]);

            $plainKey = $licenses->issueKey($subscription);
            CloudSubscriptionEvent::create([
                'cloud_subscription_id' => $subscription->id,
                'event' => 'activation_key_issued',
                'from_status' => $subscription->status,
                'to_status' => $subscription->status,
                'from_plan_id' => $subscription->commercial_plan_id,
                'to_plan_id' => $subscription->commercial_plan_id,
                'effective_at' => now(),
                'reason' => 'Restaurant owner requested an installer activation key',
                'created_by' => $request->user()->id,
            ]);

            return $plainKey;
        });

        $audit->record($request, 'cloud_activation_key.issued', $subscription, null, [
            'cloud_subscription_id' => $subscription->id,
            'key_rotated' => true,
        ]);

        return back()
            ->with('status', 'A new one-time activation key was created. Copy it now.')
            ->with('issued_license_key', $plainKey);
    }

    private function installerDetails(): array
    {
        $disk = (string) config('app_updates.installer.disk', 'updates');
        $path = ltrim((string) config('app_updates.installer.path'), '/');
        $version = trim((string) config('app_updates.installer.version'));
        $sha256 = strtolower(trim((string) config('app_updates.installer.sha256')));
        $configured = $path !== '' && $version !== '' && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1;

        try {
            $available = $configured && Storage::disk($disk)->exists($path);
            $size = $available ? Storage::disk($disk)->size($path) : null;
        } catch (Throwable) {
            $available = false;
            $size = null;
        }

        return compact('disk', 'path', 'version', 'sha256', 'available') + ['file_size' => $size];
    }

    private function publishedDownload(
        AppUpdateService $updates,
        string $app,
        string $platform,
        string $label,
        string $target,
    ): array {
        try {
            $release = $updates->latest($app, $platform);
            $available = $release instanceof AppRelease
                && $release->verified_at !== null
                && Storage::disk('updates')->exists($release->file_path);
        } catch (Throwable) {
            $release = null;
            $available = false;
        }

        return [
            'label' => $label,
            'target' => $target,
            'release' => $release,
            'available' => $available,
            'url' => $available ? route('api.app-updates.download', ['target' => $target]) : null,
        ];
    }
}
