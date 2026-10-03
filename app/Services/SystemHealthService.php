<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemHealthService
{
    public function snapshot(): array
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'cache' => $this->cacheCheck(),
            'storage' => $this->storageCheck(),
            'queue' => $this->queueCheck(),
            'reverb' => $this->reverbCheck(),
        ];

        $statuses = collect($checks)->pluck('status');
        $overall = $statuses->contains('failed') ? 'unhealthy' : ($statuses->contains('warning') ? 'degraded' : 'healthy');

        return [
            'overall' => $overall,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
            'runtime' => [
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'database' => config('database.default'),
                'queue' => config('queue.default'),
                'broadcast' => config('broadcasting.default'),
                'timezone' => config('app.timezone'),
            ],
            'devices' => $this->deviceSummary(),
        ];
    }

    private function databaseCheck(): array
    {
        $started = microtime(true);
        try {
            DB::select('select 1');
            return $this->check('Database', 'healthy', 'Connected in '.round((microtime(true) - $started) * 1000).' ms');
        } catch (Throwable $error) {
            return $this->check('Database', 'failed', 'Connection failed', $error->getMessage());
        }
    }

    private function cacheCheck(): array
    {
        try {
            $key = 'tableplay:health:'.bin2hex(random_bytes(4));
            Cache::put($key, 'ok', 10);
            $works = Cache::get($key) === 'ok';
            Cache::forget($key);
            return $this->check('Cache', $works ? 'healthy' : 'failed', $works ? 'Read and write operational' : 'Read-back did not match');
        } catch (Throwable $error) {
            return $this->check('Cache', 'failed', 'Cache check failed', $error->getMessage());
        }
    }

    private function storageCheck(): array
    {
        $path = storage_path('framework');
        $writable = is_dir($path) && is_writable($path);
        $free = @disk_free_space(storage_path());
        $detail = $writable ? 'Writable' : 'Not writable';
        if ($free !== false) $detail .= ' · '.number_format($free / 1073741824, 1).' GB free';
        return $this->check('Storage', $writable ? 'healthy' : 'failed', $detail);
    }

    private function queueCheck(): array
    {
        try {
            $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $status = $failed > 0 ? 'warning' : ($pending > 25 ? 'warning' : 'healthy');
            return $this->check('Queue', $status, $pending.' pending · '.$failed.' failed', null, ['pending' => $pending, 'failed' => $failed]);
        } catch (Throwable $error) {
            return $this->check('Queue', 'failed', 'Queue tables unavailable', $error->getMessage());
        }
    }

    private function reverbCheck(): array
    {
        $port = (int) config('reverb.servers.reverb.port', 8080);
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, .35);
        if (is_resource($socket)) {
            fclose($socket);
            return $this->check('Real-time server', 'healthy', 'Listening on port '.$port);
        }
        return $this->check('Real-time server', 'warning', 'Not reachable on port '.$port, $errorMessage ?: 'Reverb may not be running');
    }

    private function deviceSummary(): array
    {
        try {
            $minutes = max(1, (int) optional(\App\Models\RestaurantSetting::query()->first())->device_offline_minutes ?: 5);
            return [
                'registered' => Device::query()->count(),
                'active' => Device::query()->where('is_active', true)->count(),
                'online' => Device::query()->where('is_active', true)->where('last_seen_at', '>=', now()->subMinutes($minutes))->count(),
                'threshold_minutes' => $minutes,
            ];
        } catch (Throwable) {
            return ['registered' => 0, 'active' => 0, 'online' => 0, 'threshold_minutes' => 5];
        }
    }

    private function check(string $label, string $status, string $message, ?string $error = null, array $meta = []): array
    {
        return array_filter(compact('label', 'status', 'message', 'error', 'meta'), fn ($value) => $value !== null && $value !== []);
    }
}
