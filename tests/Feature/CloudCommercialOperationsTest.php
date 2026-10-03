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
