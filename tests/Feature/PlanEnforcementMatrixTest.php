<?php

namespace Tests\Feature;

use App\Models\CommercialPlan;
use App\Models\Device;
use App\Models\DevicePairing;
use App\Models\DiningTable;
use App\Models\GameSession;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Models\Role;
use App\Models\TableSession;
use App\Models\User;
use App\Services\AutomationService;
use App\Services\DevicePairingService;
use App\Services\EntitlementService;
use App\Services\GameAccessService;
use App\Services\OrderWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanEnforcementMatrixTest extends TestCase
{
    use RefreshDatabase;

    private int $pairSequence = 0;

    public function test_published_plan_catalog_and_effective_entitlements_match_the_market_matrix(): void
    {
        $matrix = [
            'trial' => [
                'name' => 'Free Trial', 'trial_days' => 14, 'paired_tables' => 3,
                'customer_app' => true, 'games' => true, 'advanced_reports' => false, 'automation' => false,
            ],
            'simple' => [
                'name' => 'Simple', 'trial_days' => 0, 'paired_tables' => 0,
                'customer_app' => false, 'games' => false, 'advanced_reports' => false, 'automation' => false,
            ],
            'best' => [
                'name' => 'Best', 'trial_days' => 0, 'paired_tables' => 5,
                'customer_app' => true, 'games' => true, 'advanced_reports' => false, 'automation' => false,
            ],
            'pro' => [
                'name' => 'Pro', 'trial_days' => 0, 'paired_tables' => 10,
                'customer_app' => true, 'games' => true, 'advanced_reports' => true, 'automation' => true,
            ],
            'premium' => [
                'name' => 'Premium', 'trial_days' => 0, 'paired_tables' => null,
                'customer_app' => true, 'games' => true, 'advanced_reports' => true, 'automation' => true,
            ],
        ];

        foreach ($matrix as $slug => $expected) {
            $plan = CommercialPlan::where('slug', $slug)->firstOrFail();

            $this->assertTrue($plan->is_active, "{$slug} must be published.");
            $this->assertSame($expected['name'], $plan->name);
            $this->assertSame($expected['trial_days'], $plan->trial_days);
            $this->assertSame($expected['paired_tables'], $plan->max_paired_tables);
            $this->assertTrue((bool) data_get($plan->features, 'staff_apps'), "{$slug} must include staff apps.");
            $this->assertTrue((bool) data_get($plan->features, 'waiter_ordering'), "{$slug} must include waiter ordering.");

            foreach (['customer_app', 'games', 'advanced_reports', 'automation'] as $feature) {
                $this->assertSame($expected[$feature], (bool) data_get($plan->features, $feature), "{$slug}.{$feature} catalog mismatch.");
            }

            $this->activate($slug);
            $state = app(EntitlementService::class)->state();
            $this->assertTrue($state['licensed']);
            $this->assertSame($slug, data_get($state, 'plan.slug'));
            $this->assertSame($expected['paired_tables'], data_get($state, 'limits.paired_tables'));
            $this->assertTrue(app(EntitlementService::class)->allows('staff_apps'));
            $this->assertTrue(app(EntitlementService::class)->allows('waiter_ordering'));

            foreach (['customer_app', 'games', 'advanced_reports', 'automation'] as $feature) {
                $this->assertSame($expected[$feature], app(EntitlementService::class)->allows($feature), "{$slug}.{$feature} runtime mismatch.");
            }
        }
    }

    public function test_every_finite_plan_enforces_its_exact_tablet_limit_and_premium_is_unlimited(): void
    {
        foreach (['trial' => 3, 'simple' => 0, 'best' => 5, 'pro' => 10] as $slug => $limit) {
            DevicePairing::where('is_active', true)->update(['is_active' => false, 'unpaired_at' => now()]);
            $this->activate($slug);

            for ($number = 1; $number <= $limit; $number++) {
                $this->pairTablet($slug.'-'.$number);
            }

            $this->assertSame($limit, DevicePairing::where('is_active', true)->count());
            $this->assertPairingDeniedByPlan($slug.'-overflow');
        }

        DevicePairing::where('is_active', true)->update(['is_active' => false, 'unpaired_at' => now()]);
        $this->activate('premium');
        foreach (range(1, 15) as $number) {
            $this->pairTablet('premium-'.$number);
        }

        $this->assertSame(15, DevicePairing::where('is_active', true)->count());
        $this->assertNull(data_get(app(EntitlementService::class)->state(), 'limits.paired_tables'));
    }

    public function test_downgrading_to_simple_immediately_blocks_an_existing_customer_tablet(): void
    {
        $this->activate('best');
        [$device, $table] = $this->pairTablet('downgrade');
        $token = $device->createToken('customer-table')->plainTextToken;
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Device-UUID' => $device->device_uuid,
            'Accept' => 'application/json',
        ];

        $this->withHeaders($headers)->getJson('/api/v1/menu')->assertOk();

        $this->activate('simple');

        $menu = $this->withHeaders($headers)->getJson('/api/v1/menu')->assertForbidden();
        $this->assertPlanDenial($menu);
        $games = $this->withHeaders($headers)->getJson('/api/v1/games')->assertForbidden();
        $this->assertPlanDenial($games);
        $session = $this->withHeaders($headers)->postJson('/api/v1/table-sessions', ['guest_count' => 2])->assertForbidden();
        $this->assertPlanDenial($session);

        $this->assertDatabaseHas('device_pairings', [
            'device_id' => $device->id,
            'dining_table_id' => $table->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('table_sessions', 0);
    }

    public function test_downgrade_keeps_the_oldest_allowed_tablets_and_premium_restores_overflow_access(): void
    {
        $this->activate('pro');
        $tablets = [];

        foreach (range(1, 6) as $number) {
            [$device, $table, $pairing] = $this->pairTablet('ordered-downgrade-'.$number);
            $tablets[] = [
                'device' => $device,
                'table' => $table,
                'pairing' => $pairing,
                'token' => $device->createToken('customer-table-'.$number)->plainTextToken,
            ];
        }

        $this->customerRequest($tablets[5])->getJson('/api/v1/menu')->assertOk();
        $this->activate('best');

        foreach (array_slice($tablets, 0, 5) as $tablet) {
            $this->customerRequest($tablet)->getJson('/api/v1/menu')->assertOk();
        }

        $overflow = $this->customerRequest($tablets[5])->getJson('/api/v1/menu')->assertForbidden();
        $this->assertPlanDenial($overflow);

        $this->activate('premium');
        $this->customerRequest($tablets[5])->getJson('/api/v1/menu')->assertOk();
        $this->assertDatabaseCount('device_pairings', 6);
        $this->assertSame(6, DevicePairing::where('is_active', true)->count());
    }

    public function test_gameplay_and_timer_creation_stop_when_the_plan_no_longer_includes_games(): void
    {
        RestaurantSetting::create(['restaurant_name' => 'TablePlay', 'game_duration_minutes' => 60]);
        $counter = $this->user('counter');
        $this->activate('best');
        [$device, $table] = $this->pairTablet('game-downgrade');
        $table->update(['status' => 'occupied']);
        $visit = TableSession::create([
            'session_code' => 'PLAN-GAME-1', 'dining_table_id' => $table->id,
            'guest_count' => 2, 'opened_at' => now(), 'status' => 'open',
        ]);
        $first = $this->order($visit, 1);
        app(OrderWorkflowService::class)->transition($first, 'confirmed', $counter->id);
        $this->assertDatabaseCount('game_sessions', 1);
        $this->assertTrue(app(GameAccessService::class)->state($visit)['unlocked']);

        $activeTimer = GameSession::where('table_session_id', $visit->id)->firstOrFail();
        $this->activate('simple');

        $this->assertFalse(app(GameAccessService::class)->state($visit)['unlocked']);

        $deviceToken = $device->createToken('customer-table')->plainTextToken;
        $this->withHeaders([
            'Authorization' => 'Bearer '.$deviceToken,
            'X-Device-UUID' => $device->device_uuid,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/games/access')->assertForbidden();

        Sanctum::actingAs($counter);
        $timerResponse = $this->postJson("/api/v1/counter/game-sessions/{$activeTimer->id}/extend", ['minutes' => 10])
            ->assertForbidden();
        $this->assertPlanDenial($timerResponse);

        $second = $this->order($visit, 2);
        $this->postJson("/api/v1/counter/orders/{$second->id}/confirm")
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
        $this->assertSame(1, GameSession::where('table_session_id', $visit->id)->count(), 'Simple must confirm orders without creating another game timer.');
    }

    public function test_report_and_automation_pages_and_apis_follow_the_plan_matrix(): void
    {
        RestaurantSetting::create([
            'restaurant_name' => 'TablePlay',
            'automation_enabled' => true,
            'auto_backup_enabled' => false,
        ]);
        $admin = $this->user('admin');
        $matrix = [
            'trial' => false,
            'simple' => false,
            'best' => false,
            'pro' => true,
            'premium' => true,
        ];

        foreach ($matrix as $slug => $allowed) {
            $this->activate($slug);

            $reportPage = $this->actingAs($admin, 'web')->get('/admin/reports');
            $automationPage = $this->actingAs($admin, 'web')->get('/admin/automation');

            Sanctum::actingAs($admin);
            $reportApi = $this->getJson('/api/v1/admin/reports');
            $automationApi = $this->getJson('/api/v1/admin/automation');

            if ($allowed) {
                $reportPage->assertOk();
                $automationPage->assertOk();
                $reportApi->assertOk()->assertJsonStructure(['sales', 'audits', 'summary']);
                $automationApi->assertOk()->assertJsonStructure(['settings', 'scheduler', 'backup', 'alerts']);
            } else {
                $reportPage->assertForbidden();
                $automationPage->assertForbidden();
                $this->assertPlanDenial($reportApi->assertForbidden());
                $this->assertPlanDenial($automationApi->assertForbidden());
            }
        }
    }

    public function test_denied_automation_mutations_do_not_change_state_and_scheduled_automation_is_skipped(): void
    {
        $settings = RestaurantSetting::create([
            'restaurant_name' => 'TablePlay',
            'automation_enabled' => true,
            'auto_backup_enabled' => false,
            'backup_time' => '03:30',
            'backup_retention_days' => 14,
            'pending_order_alert_minutes' => 5,
            'service_request_alert_minutes' => 3,
        ]);
        $admin = $this->user('admin');
        $this->activate('simple');
        Sanctum::actingAs($admin);

        $payload = [
            'automation_enabled' => false,
            'auto_backup_enabled' => false,
            'backup_time' => '04:45',
            'backup_retention_days' => 30,
            'pending_order_alert_minutes' => 10,
            'service_request_alert_minutes' => 8,
        ];

        $this->assertPlanDenial($this->putJson('/api/v1/admin/automation', $payload)->assertForbidden());
        $this->assertPlanDenial($this->postJson('/api/v1/admin/automation/run')->assertForbidden());
        $this->assertSame('03:30', $settings->fresh()->backup_time);
        $this->assertTrue($settings->fresh()->automation_enabled);
        $this->assertDatabaseCount('automation_runs', 0);

        $scheduled = app(AutomationService::class)->runScheduled();
        $this->assertTrue((bool) ($scheduled['skipped'] ?? false), 'Scheduled automation must not run when the plan excludes automation.');
        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_manual_safety_backup_remains_available_when_automation_is_not_in_the_plan(): void
    {
        RestaurantSetting::create(['restaurant_name' => 'TablePlay']);
        $admin = $this->user('admin');
        $this->activate('simple');

        $automation = \Mockery::mock(AutomationService::class);
        $automation->shouldReceive('createBackup')->twice()->andReturn([
            'filename' => 'tableplay-test-backup.sql',
            'sha256' => str_repeat('a', 64),
        ]);
        $this->app->instance(AutomationService::class, $automation);

        $this->actingAs($admin, 'web')->post('/admin/automation/backup')
            ->assertRedirect()
            ->assertSessionHas('status');

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/automation/backup')
            ->assertOk()
            ->assertJsonPath('summary.filename', 'tableplay-test-backup.sql');
    }

    public function test_core_staff_and_waiter_workflows_remain_available_on_every_plan(): void
    {
        $counter = $this->user('counter');
        $kitchen = $this->user('kitchen');
        $waiter = $this->user('waiter');

        foreach (['trial', 'simple', 'best', 'pro', 'premium'] as $slug) {
            $this->activate($slug);

            Sanctum::actingAs($counter);
            $this->getJson('/api/v1/counter/dashboard')->assertOk();
            Sanctum::actingAs($kitchen);
            $this->getJson('/api/v1/kitchen/orders')->assertOk();
            Sanctum::actingAs($waiter);
            $this->getJson('/api/v1/waiter/dashboard')->assertOk()
                ->assertJsonPath('entitlements.features.waiter_ordering', true);
        }
    }

    public function test_role_checks_still_protect_plan_gated_web_and_json_routes(): void
    {
        $admin = $this->user('admin');
        $counter = $this->user('counter');
        $kitchen = $this->user('kitchen');
        $waiter = $this->user('waiter');
        $this->activate('premium');

        $this->getJson('/api/v1/admin/reports')->assertUnauthorized();
        $this->getJson('/api/v1/admin/automation')->assertUnauthorized();
        $this->get('/admin/reports')->assertRedirect('/login');

        $this->actingAs($admin, 'web')->get('/admin/reports')->assertOk();
        $this->actingAs($counter, 'web')->get('/admin/reports')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/reports')->assertOk();
        Sanctum::actingAs($counter);
        $this->getJson('/api/v1/admin/reports')->assertForbidden();
        Sanctum::actingAs($kitchen);
        $this->getJson('/api/v1/waiter/dashboard')->assertForbidden();
        Sanctum::actingAs($waiter);
        $this->getJson('/api/v1/waiter/dashboard')->assertOk();
    }

    public function test_table_quota_cannot_be_consumed_by_staff_or_inactive_devices(): void
    {
        $this->activate('best');
        $table = DiningTable::create([
            'table_code' => 'TP-DEVICE-GUARD',
            'table_name' => 'Device Guard',
            'capacity' => 4,
            'status' => 'available',
            'is_active' => true,
        ]);

        foreach ([
            ['type' => 'counter', 'active' => true],
            ['type' => 'tablet', 'active' => false],
        ] as $case) {
            $device = Device::create([
                'device_uuid' => (string) Str::uuid(),
                'device_name' => 'Invalid pairing candidate',
                'device_type' => $case['type'],
                'is_active' => $case['active'],
            ]);

            try {
                app(DevicePairingService::class)->pair($device, $table);
                $this->fail('An ineligible device was allowed to consume a table-plan slot.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('device', $exception->errors());
            }
        }

        $this->assertSame(0, DevicePairing::where('is_active', true)->count());
    }

    private function activate(string $slug): void
    {
        $plan = CommercialPlan::where('slug', $slug)->firstOrFail();
        app(EntitlementService::class)->activate(
            $plan,
            $slug === 'trial' ? 'trial' : 'active',
            $slug === 'trial' ? $plan->trial_days : null,
        );
    }

    private function pairTablet(string $label): array
    {
        $this->pairSequence++;
        $suffix = $this->pairSequence.'-'.Str::slug($label);
        $device = Device::create([
            'device_uuid' => (string) Str::uuid(),
            'device_name' => 'Tablet '.$suffix,
            'device_type' => 'tablet',
            'is_active' => true,
        ]);
        $table = DiningTable::create([
            'table_code' => 'TP-'.$suffix,
            'table_name' => 'Table '.$suffix,
            'capacity' => 4,
            'status' => 'available',
            'is_active' => true,
        ]);

        $pairing = app(DevicePairingService::class)->pair($device, $table);
        $this->assertTrue($pairing->is_active);

        return [$device, $table, $pairing];
    }

    private function assertPairingDeniedByPlan(string $label): void
    {
        try {
            $this->pairTablet($label);
            $this->fail('Pairing above the current plan limit was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('license', $exception->errors());
        }
    }

    private function user(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => Str::headline($roleName)]);

        return User::create([
            'role_id' => $role->id,
            'name' => Str::headline($roleName),
            'username' => $roleName.Str::lower(Str::random(8)),
            'password' => 'secret-password',
            'is_active' => true,
        ]);
    }

    private function order(TableSession $visit, int $sequence): Order
    {
        return Order::create([
            'order_number' => 'PLAN-ORDER-'.$visit->id.'-'.$sequence,
            'table_session_id' => $visit->id,
            'order_sequence' => $sequence,
            'status' => 'pending',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);
    }

    private function assertPlanDenial(TestResponse $response): void
    {
        $response->assertJsonStructure(['message']);
        $this->assertStringContainsString('plan', Str::lower((string) $response->json('message')));
    }

    private function customerRequest(array $tablet): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$tablet['token'],
            'X-Device-UUID' => $tablet['device']->device_uuid,
            'Accept' => 'application/json',
        ]);
    }
}
