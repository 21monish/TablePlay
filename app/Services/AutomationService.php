<?php

namespace App\Services;

use App\Models\{AutomationRun, Device, GameSession, Order, RestaurantSetting, ServiceRequest};
use Throwable;

class AutomationService
{
    public function __construct(
        private DatabaseBackupService $backups,
        private CloudCommercialLifecycleService $commercialLifecycle,
        private EntitlementService $entitlements,
    ) {}

    public function snapshot(): array
    {
        $settings = RestaurantSetting::first();
        $orderMinutes = max(1, (int) ($settings?->pending_order_alert_minutes ?? 5));
        $requestMinutes = max(1, (int) ($settings?->service_request_alert_minutes ?? 3));
        $offlineMinutes = max(1, (int) ($settings?->device_offline_minutes ?? 5));

        return [
            'settings' => $settings,
            'scheduler' => [
                'enabled' => (bool) ($settings?->automation_enabled ?? true),
                'last_seen_at' => $settings?->last_automation_at?->toIso8601String(),
                'running' => (bool) $settings?->last_automation_at?->greaterThan(now()->subMinutes(3)),
            ],
            'backup' => $this->backups->status() + [
                'last_backup_at' => $settings?->last_backup_at?->toIso8601String(),
            ],
            'alerts' => [
                'expired_game_sessions' => GameSession::where('status', 'active')->where('expires_at', '<=', now())->count(),
                'delayed_orders' => Order::where('status', 'pending')->where('created_at', '<=', now()->subMinutes($orderMinutes))->count(),
                'delayed_requests' => ServiceRequest::where('status', 'pending')->where('created_at', '<=', now()->subMinutes($requestMinutes))->count(),
                'offline_devices' => Device::where('is_active', true)->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', now()->subMinutes($offlineMinutes)))->count(),
            ],
            'recent_runs' => AutomationRun::with('user:id,name')->latest('started_at')->limit(20)->get(),
        ];
    }

    public function updateSettings(array $data): RestaurantSetting
    {
        $this->entitlements->assertFeature('automation');

        $settings = RestaurantSetting::firstOrFail();
        $settings->update($data);
        return $settings->fresh();
    }

    public function runMaintenance(?int $userId = null, bool $record = true): array
    {
        $this->entitlements->assertFeature('automation');

        $run = $record ? AutomationRun::create([
            'type' => 'maintenance', 'status' => 'running', 'triggered_by' => $userId, 'started_at' => now(),
        ]) : null;

        try {
            $expired = 0;
            GameSession::with('tableSession')->where('status', 'active')->where('expires_at', '<=', now())
                ->orderBy('id')->chunkById(100, function ($sessions) use (&$expired) {
                    foreach ($sessions as $session) {
                        try {
                            app(GameAccessService::class)->stop($session);
                            $expired++;
                        } catch (Throwable) {
                            // A concurrent request may have already ended this timer.
                        }
                    }
                });

            $settings = RestaurantSetting::firstOrFail();
            $settings->update(['last_automation_at' => now()]);
            $summary = ['expired_game_sessions' => $expired, 'checked_at' => now()->toIso8601String()];
            $run?->update(['status' => 'success', 'summary' => $summary, 'completed_at' => now()]);
            return $summary;
        } catch (Throwable $exception) {
            $run?->update(['status' => 'failed', 'error' => $exception->getMessage(), 'completed_at' => now()]);
            throw $exception;
        }
    }

    public function createBackup(?int $userId = null): array
    {
        $run = AutomationRun::create([
            'type' => 'backup', 'status' => 'running', 'triggered_by' => $userId, 'started_at' => now(),
        ]);
        try {
            $settings = RestaurantSetting::firstOrFail();
            $summary = $this->backups->create(max(1, (int) $settings->backup_retention_days));
            $settings->update(['last_backup_at' => now()]);
            $run->update(['status' => 'success', 'summary' => $summary, 'completed_at' => now()]);
            return $summary;
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => $exception->getMessage(), 'completed_at' => now()]);
            throw $exception;
        }
    }

    public function runScheduled(): array
    {
        if (config('tableplay.mode') === 'cloud') {
            return ['commercial_lifecycle' => $this->commercialLifecycle->run()];
        }

        if (! $this->entitlements->allows('automation')) {
            return ['skipped' => true, 'reason' => 'The current TablePlay plan does not include automation.'];
        }

        $settings = RestaurantSetting::first();
        if (! $settings || ! $settings->automation_enabled) {
            return ['skipped' => true, 'reason' => 'Automation is disabled.'];
        }

        $result = ['maintenance' => $this->runMaintenance(record: false)];
        $backupDue = $settings->auto_backup_enabled
            && now()->format('H:i') >= ($settings->backup_time ?: '02:00')
            && ! AutomationRun::where('type', 'backup')->whereDate('started_at', now()->toDateString())->exists();
        if ($backupDue) {
            $result['backup'] = $this->createBackup();
        }

        return $result;
    }
}
