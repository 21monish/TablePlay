<?php

namespace Tests\Feature;

use App\Models\{CloudInvoice, CloudRestaurant, CloudSubscription, CommercialPlan, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Services\CloudCommercialLifecycleService;
use Tests\TestCase;

class CloudCommercialOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_form_creates_customer_and_activation_key_with_string_duration(): void
    {
        $plan = CommercialPlan::where('slug','best')->firstOrFail();
        $response = $this->actingAs($this->superadmin())->post(route('superadmin.cloud.restaurants.store'), [
            'name'=>'New Restaurant','owner_name'=>'Owner','owner_email'=>'owner@example.test','owner_phone'=>'9000000000',
            'commercial_plan_id'=>$plan->id,'duration_days'=>'365',
        ]);

        $response->assertRedirect()->assertSessionHas('status')->assertSessionHas('issued_license_key');
        $this->assertDatabaseHas('cloud_restaurants',['name'=>'New Restaurant']);
        $this->assertDatabaseHas('cloud_subscriptions',['commercial_plan_id'=>$plan->id,'status'=>'active']);
        $this->actingAs($this->superadmin())->get(route('superadmin.cloud.restaurants.index'))->assertOk();
    }

    public function test_superadmin_can_manage_customer_profile_and_account_status(): void
    {
        $restaurant = $this->restaurant();
        $this->actingAs($this->superadmin())->put(route('superadmin.cloud.restaurants.update', $restaurant), [
            'name'=>'Cafe Updated','owner_name'=>'Asha','owner_email'=>'asha@example.test','owner_phone'=>'9000000000',
            'billing_name'=>'Asha Foods','billing_email'=>'billing@example.test','tax_number'=>'GST-123','billing_address'=>'Pune','notes'=>'Priority onboarding',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('cloud_restaurants', ['id'=>$restaurant->id,'name'=>'Cafe Updated','tax_number'=>'GST-123']);

        $this->actingAs($this->superadmin())->post(route('superadmin.cloud.restaurants.status', $restaurant), ['status'=>'suspended'])->assertRedirect();
        $this->assertSame('suspended', $restaurant->fresh()->status);
        $this->actingAs($this->superadmin())->get(route('superadmin.cloud.index'))->assertOk()->assertSee('Cafe Updated');
    }

    public function test_subscription_changes_create_permanent_lifecycle_events(): void
    {
        [$restaurant,$subscription] = $this->restaurantWithSubscription();
        $pro = CommercialPlan::where('slug','pro')->firstOrFail();
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('superadmin.cloud.subscriptions.renew', $subscription), ['commercial_plan_id'=>$pro->id,'duration_days'=>365,'reason'=>'Customer upgrade'])->assertRedirect();
        $this->assertDatabaseHas('cloud_subscription_events', ['cloud_subscription_id'=>$subscription->id,'event'=>'plan_changed','to_plan_id'=>$pro->id]);

        $this->actingAs($admin)->post(route('superadmin.cloud.subscriptions.status', $subscription), ['status'=>'suspended','reason'=>'Payment overdue'])->assertRedirect();
        $this->assertDatabaseHas('cloud_subscription_events', ['cloud_subscription_id'=>$subscription->id,'to_status'=>'suspended','reason'=>'Payment overdue']);
    }

    public function test_browser_renewal_accepts_string_duration_and_saves_dates_key_and_event(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = $this->superadmin();

        foreach (['365', '1', '3650'] as $duration) {
            [, $subscription] = $this->restaurantWithSubscription();
            $subscription->update([
                'status' => 'expired',
                'expires_at' => now()->subDays(14),
                'grace_ends_at' => now()->subDays(7),
            ]);
            $revision = (int) $subscription->fresh()->license_revision;
            $expires = now()->addDays((int) $duration);

            $response = $this->actingAs($admin)->from(route('superadmin.cloud.index'))
                ->post(route('superadmin.cloud.subscriptions.renew', $subscription), [
                    'commercial_plan_id' => (string) $subscription->commercial_plan_id,
                    'duration_days' => $duration,
                    'reason' => 'Approved customer renewal',
                ]);

            $response->assertRedirect(route('superadmin.cloud.index'))
                ->assertSessionHasNoErrors()->assertSessionHas('status')
                ->assertSessionHas('issued_license_key');
            $renewed = $subscription->fresh();
            $this->assertSame('active', $renewed->status);
            $this->assertTrue($renewed->starts_at->equalTo(now()));
            $this->assertTrue($renewed->expires_at->equalTo($expires));
            $this->assertTrue($renewed->grace_ends_at->equalTo($expires->copy()->addDays(7)));
            $this->assertSame($revision + 1, $renewed->license_revision);
            $this->assertSame(1, $renewed->licenseKeys()->count());
            $this->assertTrue($renewed->licenseKeys()->firstOrFail()->expires_at->equalTo($renewed->grace_ends_at));
            $this->assertSame(0, $renewed->installations()->count());
            $this->assertDatabaseHas('cloud_subscription_events', [
                'cloud_subscription_id' => $subscription->id,
                'event' => 'renewed', 'from_status' => 'expired', 'to_status' => 'active',
                'reason' => 'Approved customer renewal', 'created_by' => $admin->id,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'cloud_subscription.renewed', 'entity_id' => $subscription->id,
            ]);
        }
    }

    public function test_browser_renewal_rejects_invalid_duration_without_changing_subscription(): void
    {
        [, $subscription] = $this->restaurantWithSubscription();
        $admin = $this->superadmin();
        $before = $subscription->fresh()->getAttributes();

        foreach (['0', '3651', '1.5', 'not-a-number', '', null] as $duration) {
            $this->actingAs($admin)->post(route('superadmin.cloud.subscriptions.renew', $subscription), [
                'commercial_plan_id' => (string) $subscription->commercial_plan_id,
                'duration_days' => $duration,
            ])->assertRedirect()->assertSessionHasErrors('duration_days');

            $this->assertSame($before, $subscription->fresh()->getAttributes());
            $this->assertSame(0, $subscription->licenseKeys()->count());
            $this->assertSame(0, $subscription->events()->count());
            $this->assertDatabaseMissing('audit_logs', ['action' => 'cloud_subscription.renewed']);
        }
    }

    public function test_opening_renewal_url_only_returns_to_the_dashboard_without_renewing(): void
    {
        [, $subscription] = $this->restaurantWithSubscription();
        $before = $subscription->fresh()->getAttributes();
        $this->actingAs($this->superadmin());

        foreach (['GET', 'HEAD'] as $method) {
            $this->call($method, route('superadmin.cloud.subscriptions.renew', $subscription))
                ->assertRedirect(route('superadmin.cloud.index'));
            $this->assertSame($before, $subscription->fresh()->getAttributes());
            $this->assertSame(0, $subscription->licenseKeys()->count());
            $this->assertSame(0, $subscription->events()->count());
            $this->assertDatabaseMissing('audit_logs', ['action' => 'cloud_subscription.renewed']);
        }
    }

    public function test_renewal_browser_redirect_is_protected_by_authentication_and_role(): void
    {
        [, $subscription] = $this->restaurantWithSubscription();
        $url = route('superadmin.cloud.subscriptions.renew', $subscription);
        $this->get($url)->assertRedirect(route('login'));
        $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrator']);
        $admin = User::create(['role_id' => $role->id, 'name' => 'Admin', 'username' => 'renew'.Str::random(5), 'password' => 'password123', 'is_active' => true]);
        $this->actingAs($admin)->get($url)->assertForbidden();
        $this->assertSame(0, $subscription->licenseKeys()->count());
    }

    public function test_renewal_success_redirects_to_a_get_page_even_with_action_as_referer(): void
    {
        [, $subscription] = $this->restaurantWithSubscription();
        $url = route('superadmin.cloud.subscriptions.renew', $subscription);
        $this->actingAs($this->superadmin())->from($url)->post($url, [
            'commercial_plan_id' => (string) $subscription->commercial_plan_id,
            'duration_days' => '30', 'reason' => 'Approved renewal',
        ])->assertStatus(303)->assertRedirect(route('superadmin.cloud.index'));
        $this->assertSame(1, $subscription->licenseKeys()->count());
    }

    public function test_customer_dashboard_paginates_and_preserves_filters_without_hiding_global_totals(): void
    {
        $customers = collect();
        for ($number = 0; $number < 25; $number++) {
            $restaurant = $this->restaurant();
            $restaurant->update(['name' => sprintf('Paging customer %02d', $number)]);
            $customers->push($restaurant);
        }
        $this->actingAs($this->superadmin());
        $response = $this->get(route('superadmin.cloud.index', ['q' => 'Paging', 'status' => 'active']));
        $response->assertOk()->assertViewHas('restaurants', fn ($page) =>
            $page instanceof \Illuminate\Pagination\LengthAwarePaginator
            && $page->total() === 25 && $page->count() === 20
            && $page->first()->id === $customers->last()->id
        )->assertViewHas('metrics', fn ($metrics) => $metrics['customers'] === 25)
            ->assertSee('Customer pages')->assertSee('q=Paging', false)->assertSee('page=2', false)
            ->assertSee('Paging customer 24')->assertDontSee('Paging customer 00');

        $this->get(route('superadmin.cloud.index', ['q' => 'Paging', 'status' => 'active', 'page' => 2]))
            ->assertOk()->assertViewHas('restaurants', fn ($page) => $page->count() === 5)
            ->assertSee('Paging customer 00')->assertDontSee('Paging customer 24');
    }

    public function test_customer_dashboard_fetches_only_the_eight_latest_events_per_subscription(): void
    {
        $subscriptionIds = [];
        for ($customer = 0; $customer < 2; $customer++) {
            [, $subscription] = $this->restaurantWithSubscription();
            $subscriptionIds[] = $subscription->id;
            for ($number = 0; $number < 12; $number++) {
                $subscription->events()->create([
                    'event' => 'status_changed', 'effective_at' => now(),
                    'created_at' => now()->addSeconds($number), 'reason' => 'History '.$number,
                ]);
            }
        }

        $this->actingAs($this->superadmin())->get(route('superadmin.cloud.index'))
            ->assertOk()->assertViewHas('restaurants', function ($restaurants) use ($subscriptionIds) {
                $loaded = $restaurants->flatMap(fn ($restaurant) => $restaurant->subscriptions);
                return $loaded->count() === count($subscriptionIds) && $loaded->every(fn ($subscription) =>
                    $subscription->events->count() === 8 && $subscription->events->first()->reason === 'History 11'
                );
            })->assertSee('History 11')->assertDontSee('History 0');
        $this->assertDatabaseCount('cloud_subscription_events', 24);
    }

    public function test_dashboard_query_count_does_not_grow_with_each_customer(): void
    {
        $this->restaurantWithSubscription();
        $this->actingAs($this->superadmin())->get(route('superadmin.cloud.index'))->assertOk();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        try {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            $this->get(route('superadmin.cloud.index'))->assertOk();
            $singleCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
            for ($customer = 1; $customer < 20; $customer++) {
                $this->restaurantWithSubscription();
            }
            \Illuminate\Support\Facades\DB::flushQueryLog();
            $this->get(route('superadmin.cloud.index'))->assertOk();
            $manyCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
            $this->assertLessThanOrEqual($singleCount + 2, $manyCount,
                "Dashboard queries increased from {$singleCount} to {$manyCount}.");
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
        }
    }

    public function test_invoice_and_payment_are_recorded_and_invoice_becomes_paid(): void
    {
        [$restaurant,$subscription] = $this->restaurantWithSubscription();
        $admin = $this->superadmin();
        $this->actingAs($admin)->post(route('superadmin.cloud.invoices.store', $restaurant), ['cloud_subscription_id'=>$subscription->id,'subtotal'=>1000,'tax_rate'=>18,'currency'=>'INR','due_on'=>today()->addDays(7)->format('Y-m-d')])->assertRedirect();
        $invoice = CloudInvoice::firstOrFail();
        $this->assertSame('1180.00', $invoice->total);

        $this->actingAs($admin)->post(route('superadmin.cloud.payments.store', $restaurant), ['cloud_invoice_id'=>$invoice->id,'method'=>'upi','amount'=>1180,'reference'=>'UPI-TEST','paid_at'=>now()->format('Y-m-d H:i:s')])->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('cloud_payments', ['cloud_invoice_id'=>$invoice->id,'reference'=>'UPI-TEST']);
        $this->actingAs($admin)->get(route('superadmin.cloud.invoices.show', $invoice))->assertOk()->assertSee($invoice->invoice_number);

        $payment = $invoice->payments()->firstOrFail();
        $this->actingAs($admin)->post(route('superadmin.cloud.payments.refund',$payment), ['reason'=>'Duplicate transfer'])->assertRedirect();
        $this->assertSame('refunded',$payment->fresh()->status);
        $this->assertSame('1180.00',$invoice->fresh()->refunded_amount);
    }

    public function test_scheduled_plan_change_and_expiry_lifecycle_run_automatically(): void
    {
        [$restaurant,$subscription] = $this->restaurantWithSubscription();
        $pro = CommercialPlan::where('slug','pro')->firstOrFail(); $admin=$this->superadmin();
        $when = now()->addHour();
        $this->actingAs($admin)->post(route('superadmin.cloud.subscriptions.schedule',$subscription), ['commercial_plan_id'=>$pro->id,'scheduled_change_at'=>$when->toDateTimeString(),'duration_days'=>30,'reason'=>'Next-cycle upgrade'])->assertRedirect();
        $this->travel(61)->minutes();
        $result = app(CloudCommercialLifecycleService::class)->run();
        $this->assertSame(1,$result['scheduled_changes']);
        $this->assertSame($pro->id,$subscription->fresh()->commercial_plan_id);

        $subscription->update(['status'=>'active','expires_at'=>now()->subDay(),'grace_ends_at'=>now()->addDays(2)]);
        app(CloudCommercialLifecycleService::class)->run();
        $this->assertSame('grace',$subscription->fresh()->status);
        $subscription->update(['grace_ends_at'=>now()->subMinute()]);
        app(CloudCommercialLifecycleService::class)->run();
        $this->assertSame('expired',$subscription->fresh()->status);
    }

    public function test_renewal_reminders_settings_filters_and_sanitized_export_work(): void
    {
        [$restaurant,$subscription] = $this->restaurantWithSubscription(); $subscription->update(['expires_at'=>now()->addDays(7)]); $admin=$this->superadmin();
        app(CloudCommercialLifecycleService::class)->run();
        $this->assertDatabaseHas('cloud_renewal_reminders',['cloud_subscription_id'=>$subscription->id,'days_before_expiry'=>7]);
        $this->actingAs($admin)->put(route('superadmin.cloud.settings.update'),['legal_name'=>'TablePlay Systems','tax_number'=>'GST-TP','billing_address'=>'Pune','currency'=>'INR','invoice_prefix'=>'TPS'])->assertRedirect();
        $this->actingAs($admin)->get(route('superadmin.cloud.index',['plan_id'=>$subscription->commercial_plan_id,'renewal_days'=>30]))->assertOk()->assertSee($restaurant->name);
        $this->actingAs($admin)->get(route('superadmin.cloud.export'))->assertOk()->assertDownload()->assertHeader('content-type','text/csv; charset=UTF-8');
    }

    public function test_unpaid_invoice_can_be_voided_with_a_reason(): void
    {
        [$restaurant,$subscription] = $this->restaurantWithSubscription(); $admin=$this->superadmin();
        $this->actingAs($admin)->post(route('superadmin.cloud.invoices.store',$restaurant),['cloud_subscription_id'=>$subscription->id,'subtotal'=>500,'tax_rate'=>0,'currency'=>'INR'])->assertRedirect();
        $invoice=CloudInvoice::firstOrFail();
        $this->actingAs($admin)->post(route('superadmin.cloud.invoices.void',$invoice),['reason'=>'Customer requested corrected invoice'])->assertRedirect();
        $this->assertSame('void',$invoice->fresh()->status);
    }

    public function test_restaurant_admin_cannot_access_cloud_commercial_operations(): void
    {
        $role = Role::firstOrCreate(['name'=>'admin'], ['display_name'=>'Administrator']);
        $user = User::create(['role_id'=>$role->id,'name'=>'Admin','username'=>'admin'.Str::random(5),'password'=>'password123','is_active'=>true]);
        $this->actingAs($user)->get(route('superadmin.cloud.index'))->assertForbidden();
    }

    private function restaurant(): CloudRestaurant
    {
        return CloudRestaurant::create(['restaurant_uuid'=>(string)Str::uuid(),'name'=>'Cafe Test','status'=>'active']);
    }

    private function restaurantWithSubscription(): array
    {
        $restaurant = $this->restaurant(); $plan = CommercialPlan::where('slug','best')->firstOrFail();
        $subscription = CloudSubscription::create(['cloud_restaurant_id'=>$restaurant->id,'commercial_plan_id'=>$plan->id,'license_reference'=>'TP-'.Str::upper(Str::random(14)),'status'=>'active','starts_at'=>now(),'expires_at'=>now()->addYear(),'grace_ends_at'=>now()->addYear()->addDays(7),'max_installations'=>1]);
        return [$restaurant,$subscription];
    }

    private function superadmin(): User
    {
        $role = Role::firstOrCreate(['name'=>'superadmin'], ['display_name'=>'Super Administrator']);
        return User::create(['role_id'=>$role->id,'name'=>'Super Admin','username'=>'super'.Str::random(5),'password'=>'password123','is_active'=>true]);
    }
}
