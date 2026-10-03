<?php

namespace Tests\Feature;

use App\Models\CommercialPlan;
use App\Models\RestaurantSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuperAdminPlanManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_manage_the_complete_plan_lifecycle(): void
    {
        $user = $this->user('superadmin');

        $this->actingAs($user)->post(route('superadmin.plans.store'), $this->payload([
            'name' => 'Growth Plus',
            'monthly_price' => 1499,
            'is_featured' => 1,
        ]))->assertRedirect()->assertSessionHas('status');

        $plan = CommercialPlan::where('slug', 'growth-plus')->firstOrFail();
        $this->assertTrue($plan->is_featured);
        $this->assertTrue((bool) data_get($plan->features, 'games'));

        $this->actingAs($user)->put(route('superadmin.plans.update', $plan), $this->payload([
            'name' => 'Growth Professional',
            'monthly_price' => 1999,
            'max_paired_tables' => 25,
        ]))->assertRedirect();
        $this->assertDatabaseHas('commercial_plans', ['id' => $plan->id, 'name' => 'Growth Professional', 'max_paired_tables' => 25]);

        $this->actingAs($user)->post(route('superadmin.plans.toggle', $plan))->assertRedirect();
        $this->assertFalse($plan->fresh()->is_active);
        $this->actingAs($user)->post(route('superadmin.plans.toggle', $plan))->assertRedirect();
        $this->assertTrue($plan->fresh()->is_active);

        $this->actingAs($user)->delete(route('superadmin.plans.destroy', $plan))->assertRedirect();
        $this->assertDatabaseMissing('commercial_plans', ['id' => $plan->id]);
    }

    public function test_plan_with_license_history_must_be_archived_instead_of_deleted(): void
    {
        $user = $this->user('superadmin');
        $trial = CommercialPlan::where('slug', 'trial')->firstOrFail();

        $this->actingAs($user)->delete(route('superadmin.plans.destroy', $trial))
            ->assertRedirect()
            ->assertSessionHasErrors('plan');

        $this->assertDatabaseHas('commercial_plans', ['id' => $trial->id]);
    }

    public function test_archived_plan_cannot_be_activated(): void
    {
        $user = $this->user('superadmin');
        $plan = CommercialPlan::where('slug', 'best')->firstOrFail();
        $plan->update(['is_active' => false]);

        $this->actingAs($user)->post(route('superadmin.activate'), ['plan_id' => $plan->id])
            ->assertSessionHasErrors('plan_id');
    }

    public function test_current_subscription_can_be_suspended_and_resumed(): void
    {
        $user = $this->user('superadmin');
        $subscription = RestaurantSubscription::latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('superadmin.suspend', $subscription))->assertRedirect();
        $this->assertSame('suspended', $subscription->fresh()->status);

        $this->actingAs($user)->post(route('superadmin.resume', $subscription))->assertRedirect();
        $this->assertContains($subscription->fresh()->status, ['trial', 'active', 'grace']);
    }

    public function test_restaurant_admin_cannot_manage_commercial_plans(): void
    {
        $this->actingAs($this->user('admin'))
            ->post(route('superadmin.plans.store'), $this->payload())
            ->assertForbidden();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Growth',
            'description' => 'For growing restaurants.',
            'trial_days' => 0,
            'grace_days' => 7,
            'max_paired_tables' => 15,
            'monthly_price' => 999,
            'annual_price' => 9999,
            'sort_order' => 20,
            'is_active' => 1,
            'is_featured' => 0,
            'features' => ['staff_apps', 'customer_app', 'games'],
        ], $overrides);
    }

    private function user(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role], ['display_name' => str($role)->headline()]);

        return User::create([
            'role_id' => $roleModel->id,
            'name' => str($role)->headline(),
            'username' => $role.Str::random(5),
            'password' => 'password123',
            'is_active' => true,
        ]);
    }
}
