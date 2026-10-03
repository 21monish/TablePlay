<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AutomationService;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function show(AutomationService $automation): array
    {
        return $automation->snapshot();
    }

    public function update(Request $request, AutomationService $automation): array
    {
        $data = $request->validate([
            'automation_enabled' => ['required', 'boolean'],
            'auto_backup_enabled' => ['required', 'boolean'],
            'backup_time' => ['required', 'date_format:H:i'],
            'backup_retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'pending_order_alert_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'service_request_alert_minutes' => ['required', 'integer', 'min:1', 'max:120'],
        ]);
        $automation->updateSettings($data);
        return $automation->snapshot();
    }

    public function run(Request $request, AutomationService $automation): array
    {
        return ['message' => 'Maintenance completed.', 'summary' => $automation->runMaintenance($request->user()->id)];
    }

    public function backup(Request $request, AutomationService $automation): array
    {
        return ['message' => 'Verified database backup created.', 'summary' => $automation->createBackup($request->user()->id)];
    }
}
