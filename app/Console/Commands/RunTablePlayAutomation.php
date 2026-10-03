<?php

namespace App\Console\Commands;

use App\Services\AutomationService;
use Illuminate\Console\Command;
use Throwable;

class RunTablePlayAutomation extends Command
{
    protected $signature = 'tableplay:automation-run';
    protected $description = 'Run safe TablePlay maintenance and the scheduled local backup when due';

    public function handle(AutomationService $automation): int
    {
        try {
            $result = $automation->runScheduled();
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
