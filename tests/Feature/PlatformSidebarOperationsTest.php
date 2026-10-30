<?php

namespace Tests\Feature;

use App\Models\{AuditLog, CloudInstallation, CloudInvoice, CloudPayment, CloudRestaurant, CloudSubscription, CommercialPlan, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformSidebarOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['invoices', 'payments', 'renewals', 'installations', 'activity'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tableplay.mode' => 'cloud', 'tableplay.require_privileged_email_verification' => false]);
    }

    private function user(string $role = 'superadmin'): User
    {
        return User::create(['name' => 'QA Actor '.Str::random(6), 'username' => Str::random(12), 'password' => 'test-only-password', 'is_active' => true,
            'role_id' => Role::firstOrCreate(['name' => $role], ['display_name' => ucfirst($role)])->id]);
    }

    private function restaurant(string $name = 'Sidebar Cafe'): CloudRestaurant
    {
        return CloudRestaurant::create(['restaurant_uuid' => (string) Str::uuid(), 'name' => $name, 'status' => 'active']);
    }

    private function subscription(CloudRestaurant $restaurant, array $attributes = []): CloudSubscription
    {
        return $restaurant->subscriptions()->create(array_merge(['commercial_plan_id' => CommercialPlan::where('slug', 'best')->value('id'), 'license_reference' => 'QA-'.Str::uuid(), 'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addDays(10)], $attributes));
    }

    private function invoice(CloudRestaurant $restaurant, array $attributes = []): CloudInvoice
    {
        return $restaurant->invoices()->create(array_merge(['invoice_number' => 'QA-INV-'.Str::uuid(), 'status' => 'issued', 'currency' => 'INR', 'subtotal' => '100.00', 'tax_rate' => 0, 'tax_amount' => 0, 'total' => '100.00', 'issued_on' => today()], $attributes));
    }

    public function test_sidebar_groups_have_working_get_links_and_only_one_current_page(): void
    {
        $this->actingAs($this->user());
        foreach (self::PAGES as $section) {
            $response = $this->get(route('superadmin.'.$section))->assertOk();
            $dom = new \DOMDocument();
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            $this->assertSame(3, $xpath->query('//aside//nav/div[@role="group"]')->length);
            $this->assertSame(11, $xpath->query('//aside//nav//a')->length);
            $current = $xpath->query('//aside//nav//a[@aria-current="page"]');
            $this->assertSame(1, $current->length);
            $this->assertSame(route('superadmin.'.$section), $current->item(0)->getAttribute('href'));
            foreach (['overview', 'users', 'restaurants', 'index', 'cloud.index', ...self::PAGES] as $destination) {
                $this->assertSame(1, $xpath->query('//aside//nav//a[@href="'.route('superadmin.'.$destination).'"]')->length);
            }
        }
        $this->get(route('superadmin.cloud.index'))->assertOk()->assertSee('id="offline-activation"', false)->assertSee('id="cloud-installations"', false);
    }

    public function test_operations_pages_are_superadmin_only_and_require_verified_active_access(): void
    {
        foreach (self::PAGES as $page) $this->get(route('superadmin.'.$page))->assertRedirect(route('login'));
        foreach (['admin', 'restaurant_owner', 'counter', 'kitchen', 'waiter'] as $role) {
            $this->actingAs($this->user($role));
            foreach (self::PAGES as $page) $this->get(route('superadmin.'.$page))->assertForbidden();
        }
        $admin = $this->user();
        $admin->update(['is_active' => false]);
        $this->actingAs($admin)->get(route('superadmin.activity'))->assertForbidden();
        config(['tableplay.require_privileged_email_verification' => true]);
        $this->actingAs($this->user())->get(route('superadmin.payments'))->assertRedirect(route('verification.notice'));
    }

    public function test_invoice_search_is_literal_and_case_insensitive_and_shows_remaining_balance(): void
    {
        $restaurant = $this->restaurant('100%_Sidebar Cafe');
        $invoice = $this->invoice($restaurant);
        $restaurant->payments()->create(['cloud_invoice_id' => $invoice->id, 'payment_number' => 'QA-PARTIAL', 'method' => 'bank', 'amount' => '24.75', 'paid_at' => now(), 'status' => 'completed']);
        $this->invoice($this->restaurant('100 other Sidebar Cafe'), ['status' => 'void']);
        $this->actingAs($this->user())->get(route('superadmin.invoices', ['q' => '100%_sidebar', 'status' => 'issued']))
            ->assertOk()->assertSee('75.25')->assertSee(route('superadmin.cloud.invoices.show', $invoice), false)
            ->assertViewHas('records', fn ($records) => $records->total() === 1);
    }

    public function test_payments_keep_refunds_and_unassigned_currency_visible_without_guessing(): void
    {
        $restaurant = $this->restaurant();
        $invoice = $this->invoice($restaurant, ['currency' => 'USD']);
        foreach ([['QA-COMPLETED', 'completed', null], ['QA-REFUNDED', 'refunded', $invoice->id]] as [$number, $status, $invoiceId]) {
            $restaurant->payments()->create(['cloud_invoice_id' => $invoiceId, 'payment_number' => $number, 'method' => 'upi', 'amount' => '42.15', 'paid_at' => now()->subDay(), 'status' => $status, 'refunded_at' => $status === 'refunded' ? now() : null]);
        }
        $this->actingAs($this->user())->get(route('superadmin.payments'))->assertOk()->assertSee('Unassigned currency')->assertSee('USD 42.15')->assertSee('Refunded');
        $this->get(route('superadmin.payments', ['status' => 'refunded']))->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1)->assertDontSee('QA-COMPLETED');
    }

    public function test_renewals_only_include_latest_subscriptions_and_never_renew_on_get(): void
    {
        $restaurant = $this->restaurant('Recently renewed');
        $this->subscription($restaurant, ['expires_at' => now()->addDays(2)]);
        $this->subscription($restaurant, ['expires_at' => now()->addDays(120)]);
        $due = $this->subscription($this->restaurant('Due Soon'), ['expires_at' => now()->addDays(5)]);
        $overdue = $this->subscription($this->restaurant('Overdue Cafe'), ['expires_at' => now()->subDays(3), 'status' => 'expired']);
        $this->subscription($this->restaurant('No expiry'), ['expires_at' => null]);
        $before = CloudSubscription::orderBy('id')->get()->toArray();
        $this->actingAs($this->user())->get(route('superadmin.renewals', ['window' => '7']))->assertOk()
            ->assertViewHas('records', fn ($records) => $records->modelKeys() === [$due->id]);
        $this->get(route('superadmin.renewals', ['window' => 'overdue']))->assertOk()
            ->assertViewHas('records', fn ($records) => $records->modelKeys() === [$overdue->id]);
        $this->assertSame($before, CloudSubscription::orderBy('id')->get()->toArray());
    }

    public function test_installations_never_select_activation_secrets_and_are_not_labelled_live(): void
    {
        $subscription = $this->subscription($this->restaurant());
        CloudInstallation::create(['cloud_subscription_id' => $subscription->id, 'installation_uuid' => (string) Str::uuid(), 'device_name' => 'QA SERVER', 'activation_token_hash' => str_repeat('a', 64), 'device_fingerprint' => 'do-not-show-fingerprint', 'status' => 'active', 'server_version' => '2.5.8', 'activated_at' => now(), 'last_seen_at' => now()->subDays(20)]);
        $this->actingAs($this->user())->get(route('superadmin.installations', ['q' => 'qa server', 'status' => 'active']))->assertOk()->assertSee('2.5.8')->assertSee('does not prove a server is currently online')
            ->assertDontSee(str_repeat('a', 64))->assertDontSee('do-not-show-fingerprint')
            ->assertViewHas('records', function ($records) {
                $this->assertSame(1, $records->total());
                $this->assertArrayNotHasKey('activation_token_hash', $records->first()->getAttributes());
                return true;
            });
    }

    public function test_audit_log_omits_payloads_and_ips_but_retains_deleted_actor_names(): void
    {
        $actor = $this->user('counter');
        $actor->delete();
        AuditLog::create(['user_id' => $actor->id, 'action' => 'cloud_payment.recorded', 'entity_type' => CloudPayment::class, 'entity_id' => 42, 'old_values' => ['secret' => 'old-hidden-payload'], 'new_values' => ['activation_token' => 'new-hidden-payload'], 'ip_address' => '192.0.2.99', 'created_at' => now()]);
        $this->actingAs($this->user())->get(route('superadmin.activity', ['q' => $actor->name]))->assertOk()->assertSee($actor->name)->assertSee('deleted account')->assertSee('cloud_payment.recorded')->assertSee('CloudPayment')
            ->assertDontSee('old-hidden-payload')->assertDontSee('new-hidden-payload')->assertDontSee('192.0.2.99')
            ->assertViewHas('records', function ($records) {
                $this->assertSame(1, $records->total());
                foreach (['old_values', 'new_values', 'ip_address'] as $field) $this->assertArrayNotHasKey($field, $records->first()->getAttributes());
                return true;
            });
    }

    public function test_page_filters_validate_paginate_and_do_not_mutate_records(): void
    {
        $restaurant = $this->restaurant();
        foreach (range(1, 24) as $number) $this->invoice($restaurant, ['invoice_number' => 'QA-PAGE-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
        $this->actingAs($this->user());
        $counts = [User::withTrashed()->count(), CloudSubscription::count(), CloudInvoice::count(), CloudPayment::count(), AuditLog::count()];
        DB::enableQueryLog();
        $response = $this->get(route('superadmin.invoices', ['q' => 'QA-PAGE']));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $response->assertOk()->assertViewHas('records', function ($records) {
            $this->assertCount(20, $records->items());
            $this->assertSame(24, $records->total());
            $this->assertStringContainsString('q=QA-PAGE', $records->nextPageUrl());
            return true;
        });
        $this->assertLessThan(20, count($queries));
        foreach (self::PAGES as $page) {
            $this->getJson(route('superadmin.'.$page, ['status' => 'invalid-status']))->assertUnprocessable();
            $this->getJson(route('superadmin.'.$page, ['q' => ['array-not-string']]))->assertUnprocessable();
            $this->get(route('superadmin.'.$page))->assertOk();
        }
        $this->getJson(route('superadmin.renewals', ['window' => 'invalid']))->assertUnprocessable();
        $this->assertSame($counts, [User::withTrashed()->count(), CloudSubscription::count(), CloudInvoice::count(), CloudPayment::count(), AuditLog::count()]);
    }
}
