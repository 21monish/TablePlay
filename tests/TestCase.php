<?php

namespace Tests;

use App\Models\CommercialPlan;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /** Workflow tests run with an explicit test-only signed trial fixture. */
    protected bool $withTestLicense = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->withTestLicense
            && Schema::hasTable('commercial_plans')
            && Schema::hasTable('restaurant_subscriptions')
            && ! app(EntitlementService::class)->subscription()) {
            $trial = CommercialPlan::where('slug', 'trial')->first();
            if ($trial) app(EntitlementService::class)->activate($trial, 'trial', 30);
        }
    }
}
