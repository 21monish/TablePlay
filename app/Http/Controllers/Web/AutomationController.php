<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AutomationService;
use App\Services\DatabaseBackupService;
use Illuminate\Http\Request;
use Throwable;

class AutomationController extends Controller
{
    public function index(AutomationService $automation)
    {
        return view('admin.automation', $automation->snapshot());
    }

    public function update(Request $request, AutomationService $automation)
    {
        $data = $request->validate($this->rules());
        $automation->updateSettings($data);
        return back()->with('status', 'Automation settings saved. The Windows service will apply them automatically.');
    }

    public function run(Request $request, AutomationService $automation)
    {
        try {
            $summary = $automation->runMaintenance($request->user()->id);
            return back()->with('status', "Maintenance completed. {$summary['expired_game_sessions']} overdue game timer(s) were closed.");
        } catch (Throwable $exception) {
            report($exception);
            return back()->with('error', 'Maintenance could not finish: '.$exception->getMessage());
        }
    }

    public function backup(Request $request, AutomationService $automation)
    {
        try {
            $summary = $automation->createBackup($request->user()->id);
            return back()->with('status', 'Verified database backup created: '.$summary['filename']);
        } catch (Throwable $exception) {
            report($exception);
            return back()->with('error', 'Backup could not be created: '.$exception->getMessage());
        }
    }

    public function download(string $filename, DatabaseBackupService $backups)
    {
        try {
            return response()->download($backups->downloadPath($filename), $filename, [
                'Content-Type' => 'application/sql',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    private function rules(): array
    {
        return [
            'automation_enabled' => ['required', 'boolean'],
            'auto_backup_enabled' => ['required', 'boolean'],
            'backup_time' => ['required', 'date_format:H:i'],
            'backup_retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'pending_order_alert_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'service_request_alert_minutes' => ['required', 'integer', 'min:1', 'max:120'],
        ];
    }
}
