<?php

namespace Tests\Feature;

use App\Models\{CommercialPlan, RestaurantSetting, Role, User};
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Models\TablePlayInstallation;
use Tests\TestCase;

class ConnectionAndLicenseExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_days_are_server_calculated_and_never_negative(): void
    {
        $this->travelTo('2026-09-22 10:00:00');
        app(EntitlementService::class)->activate(
            CommercialPlan::where('slug', 'trial')->firstOrFail(),
            'trial',
            30,
        );

        $this->assertSame(30, app(EntitlementService::class)->state()['days_remaining']);
        $this->travel(29)->days();
        $this->assertSame(1, app(EntitlementService::class)->state()['days_remaining']);
        $this->travel(2)->days();
        $state = app(EntitlementService::class)->state();
        $this->assertSame(0, $state['days_remaining']);
        $this->assertSame('grace', $state['status']);
    }

    public function test_license_page_explains_subscription_and_verification_separately(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('admin.license.index'))
            ->assertOk()
            ->assertSee('Subscription expires')
            ->assertSee('Offline verification due')
            ->assertSee('Calculated by the restaurant server')
            ->assertSee('Plan features')
            ->assertSee('synchronization history');
    }

    public function test_web_credentials_have_accessible_visibility_controls_and_server_validation(): void
    {
        $admin = $this->user('admin');
        RestaurantSetting::create(['restaurant_name' => 'Experience Restaurant']);
        $this->get(route('login'))->assertOk()
            ->assertSee('data-secret-toggle', false)
            ->assertSee('aria-controls="login-credential"', false);

        $this->actingAs($admin)->get(route('admin.team'))->assertOk()
            ->assertSee('aria-controls="staff-password"', false)
            ->assertSee('aria-controls="staff-pin"', false);
        $this->actingAs($admin)->get(route('admin.setup.index'))->assertOk()
            ->assertSee('aria-controls="setup-staff-password"', false);

        $counterRole = Role::firstOrCreate(['name' => 'counter'], ['display_name' => 'Counter']);
        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'role_id' => $counterRole->id,
            'name' => 'Invalid Staff',
            'username' => 'bad staff name',
            'password' => 'short',
            'pin' => '12ab',
        ])->assertSessionHasErrors(['username', 'password', 'pin']);

        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'role_id' => $counterRole->id,
            'name' => 'Valid Staff',
            'username' => 'valid.staff',
            'password' => str_repeat('a', 256),
            'pin' => '1234',
        ])->assertSessionHasErrors('password');
    }

    private function user(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role], ['display_name' => str($role)->headline()]);
        return User::create([
            'role_id' => $roleModel->id,
            'name' => str($role)->headline(),
            'username' => $role.'.experience',
            'password' => 'password123',
            'is_active' => true,
        ]);
    }

    public function test_activation_connection_failure_returns_validation_without_changing_the_licence(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 60: SSL certificate problem'));
        $before = app(EntitlementService::class)->subscription()->id;
        $this->actingAs($this->user('admin'))->from(route('admin.license.index'))
            ->post(route('admin.license.activate'), ['cloud_url' => 'https://cloud.tableplay.test', 'license_key' => 'TP-TEST'])
            ->assertRedirect(route('admin.license.index'))
            ->assertSessionHasErrors(['cloud_url' => 'This server cannot verify the TablePlay Cloud HTTPS certificate. Repair the TablePlay PHP certificate trust configuration, then try activation again.']);
        $this->assertSame($before, app(EntitlementService::class)->subscription()->id);
        $this->assertNull(TablePlayInstallation::findOrFail(1)->activation_token);
    }

    public function test_activation_timeout_is_recoverable_without_retrying_a_single_use_key(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'));
        $this->actingAs($this->user('admin'))->from(route('admin.license.index'))
            ->post(route('admin.license.activate'), ['cloud_url' => 'https://cloud.tableplay.test', 'license_key' => 'TP-TEST'])
            ->assertRedirect(route('admin.license.index'))->assertSessionHasErrors('cloud_url');
        $this->assertNull(TablePlayInstallation::findOrFail(1)->activation_token);
    }

    public function test_activation_reports_incorrect_cloud_url_and_temporary_cloud_errors(): void
    {
        $admin = $this->user('admin');
        Http::fakeSequence()->push('<html>Not Found</html>', 404)->push('<html>Server Error</html>', 503);
        $this->actingAs($admin)->post(route('admin.license.activate'), [
            'cloud_url' => 'https://cloud.tableplay.test/account', 'license_key' => 'TP-TEST',
        ])->assertSessionHasErrors('cloud_url');
        $this->actingAs($admin)->post(route('admin.license.activate'), [
            'cloud_url' => 'https://cloud.tableplay.test', 'license_key' => 'TP-TEST',
        ])->assertSessionHasErrors(['license_key' => 'TablePlay Cloud is temporarily unable to activate this licence. Please try again shortly.']);
    }
}
