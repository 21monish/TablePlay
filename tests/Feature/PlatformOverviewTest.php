<?php

namespace Tests\Feature;

use App\Models\{AuditLog, CloudInvoice, CloudPayment, CloudRestaurant, CloudRestaurantFinancialReport, CloudSubscription, CommercialPlan, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
        config(['tableplay.mode' => 'cloud', 'tableplay.require_privileged_email_verification' => false]);
    }

    private function user(string $role = 'superadmin', array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Test '.Str::random(8), 'username' => Str::random(12), 'password' => 'isolated-test-password',
            'role_id' => Role::firstOrCreate(['name' => $role], ['display_name' => ucfirst($role)])->id, 'is_active' => true,
        ], $attributes));
        if (isset($attributes['email_verified_at'])) $user->forceFill(['email_verified_at' => $attributes['email_verified_at']])->save();
        return $user;
    }

    private function restaurant(?User $owner = null, array $attributes = []): CloudRestaurant
    {
        return CloudRestaurant::create(array_merge(['restaurant_uuid' => (string) Str::uuid(), 'name' => 'Test Restaurant '.Str::random(8), 'status' => 'active', 'owner_user_id' => $owner?->id], $attributes));
    }

    private function invoice(CloudRestaurant $restaurant, string $currency = 'INR', string $total = '100.00', string $status = 'issued'): CloudInvoice
    {
        return $restaurant->invoices()->create(['invoice_number' => 'TEST-'.Str::uuid(), 'currency' => $currency, 'subtotal' => $total, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => $total, 'status' => $status, 'issued_on' => '2026-10-01']);
    }

    private function payment(CloudRestaurant $restaurant, ?CloudInvoice $invoice, array $attributes = []): CloudPayment
    {
        return $restaurant->payments()->create(array_merge(['cloud_invoice_id' => $invoice?->id, 'payment_number' => 'TEST-'.Str::uuid(), 'method' => 'bank', 'amount' => '100.00', 'paid_at' => '2026-10-05 12:00:00', 'status' => 'completed'], $attributes));
    }

    private function reportData(array $attributes = []): array
    {
        return array_merge(['month' => '2026-10', 'currency' => 'INR', 'net_sales' => '123456.78', 'expenses' => '100000.01', 'revision' => 0, 'share_totals' => 1], $attributes);
    }

    public function test_new_platform_pages_require_an_active_verified_superadmin(): void
    {
        foreach (['superadmin.overview', 'superadmin.users', 'superadmin.restaurants'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        foreach (['admin', 'counter', 'kitchen', 'waiter', 'restaurant_owner'] as $role) {
            $this->actingAs($this->user($role));
            foreach (['superadmin.overview', 'superadmin.users', 'superadmin.restaurants'] as $route) {
                $this->get(route($route))->assertForbidden();
            }
        }
        $this->actingAs($this->user('superadmin', ['is_active' => false]))->get(route('superadmin.overview'))->assertForbidden();
        config(['tableplay.require_privileged_email_verification' => true]);
        $this->actingAs($this->user())->get(route('superadmin.users'))->assertRedirect(route('verification.notice'));
    }

    public function test_user_directory_is_paginated_searchable_and_does_not_select_credentials(): void
    {
        $admin = $this->user();
        foreach (range(1, 23) as $number) $this->user('counter', ['name' => sprintf('Crew %02d', $number)]);
        $owner = $this->user('restaurant_owner', ['name' => 'Alice 100%_Cafe', 'email' => 'alice@example.test', 'email_verified_at' => now()]);
        $this->restaurant($owner, ['name' => 'Alice Cafe']);
        $deleted = $this->user('counter', ['name' => 'Archived Crew']);
        $deleted->delete();
        $this->actingAs($admin)->get(route('superadmin.users', ['role' => 'counter']))->assertOk()->assertViewHas('users', function ($users) {
            $this->assertCount(20, $users->items());
            $this->assertSame(24, $users->total());
            $this->assertArrayNotHasKey('password', $users->first()->getAttributes());
            $this->assertArrayNotHasKey('pin', $users->first()->getAttributes());
            $this->assertArrayNotHasKey('remember_token', $users->first()->getAttributes());
            return true;
        });
        $this->get(route('superadmin.users', ['q' => '100%_cafe', 'role' => 'restaurant_owner', 'verified' => 'yes']))
            ->assertOk()->assertSee('Alice Cafe')->assertViewHas('users', fn ($users) => $users->total() === 1);
        $this->get(route('superadmin.users', ['status' => 'deleted']))->assertOk()->assertSee('Archived Crew')->assertDontSee('Crew 01');
        $this->getJson(route('superadmin.users', ['role' => ['superadmin']]))->assertUnprocessable();
    }

    public function test_subscription_cash_uses_payment_and_refund_dates_and_keeps_currencies_separate(): void
    {
        $restaurant = $this->restaurant();
        $inr = $this->invoice($restaurant);
        $usd = $this->invoice($restaurant, 'USD');
        $this->payment($restaurant, $inr, ['amount' => '200.25']);
        $this->payment($restaurant, $inr, ['amount' => '40.10', 'status' => 'refunded', 'refunded_at' => '2026-10-10']);
        $this->payment($restaurant, $inr, ['amount' => '50.05', 'paid_at' => '2026-09-10', 'status' => 'refunded', 'refunded_at' => '2026-10-20']);
        $this->payment($restaurant, $inr, ['amount' => '17.01', 'status' => 'refunded', 'refunded_at' => '2026-11-01']);
        $this->payment($restaurant, $inr, ['amount' => '1000.00', 'paid_at' => '2026-11-01']);
        $this->payment($restaurant, $usd, ['amount' => '9.99']);
        $this->payment($restaurant, null, ['amount' => '15.50']);
        $response = $this->actingAs($this->user())->get(route('superadmin.overview', ['month' => '2026-10']))->assertOk();
        $cash = $response->viewData('cash')->keyBy('currency');
        $this->assertCount(3, $cash);
        $this->assertEquals(257.36, $cash['INR']->receipts);
        $this->assertEquals(90.15, $cash['INR']->refunds);
        $this->assertSame(16721, $cash['INR']->net_cents);
        $this->assertSame(999, $cash['USD']->net_cents);
        $this->assertSame(1550, $cash['UNASSIGNED']->net_cents);
        $response->assertSee('Unassigned currency')->assertSee('Not restaurant food sales or platform profit.');
    }

    public function test_outstanding_balances_deduct_partial_payments_and_exclude_void_or_draft_invoices(): void
    {
        $restaurant = $this->restaurant();
        $partial = $this->invoice($restaurant, 'INR', '100.00');
        $this->payment($restaurant, $partial, ['amount' => '35.50']);
        $this->payment($restaurant, $partial, ['amount' => '10.00', 'status' => 'refunded', 'refunded_at' => now()]);
        $overpaid = $this->invoice($restaurant, 'INR', '50.00');
        $this->payment($restaurant, $overpaid, ['amount' => '75.00']);
        $this->invoice($restaurant, 'INR', '9000.00', 'void');
        $this->invoice($restaurant, 'INR', '8000.00', 'draft');
        $this->invoice($restaurant, 'USD', '20.00', 'overdue');
        $response = $this->actingAs($this->user())->get(route('superadmin.overview'))->assertOk();
        $balances = $response->viewData('outstanding')->keyBy('currency');
        $this->assertEquals(64.50, $balances['INR']->balance);
        $this->assertEquals(20.00, $balances['USD']->balance);
    }

    public function test_restaurant_directory_shows_latest_plan_owner_report_and_missing_reports_honestly(): void
    {
        $owner = $this->user('restaurant_owner', ['name' => 'Test Owner']);
        $restaurant = $this->restaurant($owner, ['name' => 'Maple Restaurant']);
        $this->restaurant(null, ['name' => 'No Report Restaurant']);
        foreach (['trial', 'best'] as $slug) $restaurant->subscriptions()->create(['commercial_plan_id' => CommercialPlan::where('slug', $slug)->firstOrFail()->id, 'license_reference' => 'TEST-'.Str::uuid(), 'starts_at' => now(), 'status' => 'active']);
        $restaurant->financialReports()->create(['period_month' => '2026-10-01', 'currency' => 'INR', 'net_sales' => '100.00', 'expenses' => '125.50', 'notes' => '<script>alert(1)</script>']);
        $this->actingAs($this->user())->get(route('superadmin.restaurants'))->assertOk()->assertSee('Test Owner')->assertSee('-25.50')->assertSee('Not reported for this month')->assertDontSee('<script>alert(1)</script>', false)
            ->assertViewHas('restaurants', fn ($items) => $items->firstWhere('id', $restaurant->id)->currentSubscription->commercial_plan_id === CommercialPlan::where('slug', 'best')->value('id'));
        $this->get(route('superadmin.restaurants', ['q' => 'maple']))->assertViewHas('restaurants', fn ($items) => $items->total() === 1);
        $this->get(route('superadmin.overview'))->assertViewHas('profitTotals', function ($rows) {
            $this->assertEquals(-25.50, $rows->first()->profit);
            $this->assertSame(1, (int) $rows->first()->reports);
            return true;
        });
    }

    public function test_owner_can_submit_and_correct_only_their_own_monthly_totals_with_audit_history(): void
    {
        $owner = $this->user('restaurant_owner');
        $own = $this->restaurant($owner);
        $other = $this->restaurant($this->user('restaurant_owner'));
        $this->actingAs($owner)->post(route('account.financial-reports.store'), $this->reportData(['cloud_restaurant_id' => $other->id]))
            ->assertStatus(303)->assertRedirect(route('account.financial-reports.index', ['month' => '2026-10']));
        $report = $own->financialReports()->firstOrFail();
        $this->assertSame(2345677, $report->profit_cents);
        $this->assertSame($owner->id, $report->submitted_by);
        $this->assertSame(0, $other->financialReports()->count());
        $this->get(route('account.financial-reports.index'))->assertOk()->assertSee('23,456.77');
        $this->post(route('account.financial-reports.store'), $this->reportData(['revision' => 1, 'expenses' => '124000.01']))->assertStatus(303);
        $this->assertSame(2, $report->fresh()->revision);
        $this->assertSame(-54323, $report->fresh()->profit_cents);
        $this->assertSame(2, AuditLog::where('action', 'restaurant_financial_report.submitted')->count());
        $this->assertSame(1, CloudRestaurantFinancialReport::count());
    }

    public function test_duplicate_or_stale_submission_cannot_silently_overwrite_a_report(): void
    {
        $owner = $this->user('restaurant_owner');
        $restaurant = $this->restaurant($owner);
        $this->actingAs($owner)->post(route('account.financial-reports.store'), $this->reportData())->assertStatus(303);
        $this->postJson(route('account.financial-reports.store'), $this->reportData(['expenses' => '0']))->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame('100000.01', $restaurant->financialReports()->firstOrFail()->expenses);
        $this->assertSame(1, AuditLog::where('action', 'restaurant_financial_report.submitted')->count());
    }

    public function test_owner_report_history_is_isolated_and_inactive_restaurants_cannot_submit(): void
    {
        $owner = $this->user('restaurant_owner');
        $restaurant = $this->restaurant($owner, ['status' => 'suspended']);
        $other = $this->restaurant($this->user('restaurant_owner'));
        $other->financialReports()->create(['period_month' => '2026-10-01', 'currency' => 'USD', 'net_sales' => 555, 'expenses' => 1]);
        $this->actingAs($owner)->get(route('account.financial-reports.index'))->assertOk()->assertViewHas('history', fn ($items) => $items->total() === 0);
        $this->post(route('account.financial-reports.store'), $this->reportData())->assertForbidden();
        $this->assertSame(0, $restaurant->financialReports()->count());
    }

    public function test_reports_require_owner_role_consent_valid_precision_and_a_nonfuture_month(): void
    {
        $this->actingAs($this->user())->post(route('account.financial-reports.store'), $this->reportData())->assertForbidden();
        $owner = $this->user('restaurant_owner');
        $this->restaurant($owner);
        $this->actingAs($owner);
        foreach ([['share_totals' => 0], ['net_sales' => '-1'], ['expenses' => '1.005'], ['currency' => 'IN'], ['net_sales' => '1000000000'], ['month' => '2026-11'], ['month' => '1999-12'], ['month' => '2026-13']] as $invalid) {
            $this->postJson(route('account.financial-reports.store'), $this->reportData($invalid))->assertUnprocessable();
        }
        $this->assertSame(0, CloudRestaurantFinancialReport::count());
    }

    public function test_empty_pages_and_report_filters_render_and_validate_without_mutations(): void
    {
        $this->actingAs($this->user());
        $before = [CloudSubscription::count(), CloudInvoice::count(), CloudPayment::count(), AuditLog::count()];
        $this->get(route('superadmin.overview'))->assertOk()->assertSee('No restaurant financial reports yet.');
        $this->get(route('superadmin.restaurants'))->assertOk()->assertSee('No restaurants match these filters.');
        $this->getJson(route('superadmin.overview', ['month' => 'not-a-month']))->assertUnprocessable();
        $this->getJson(route('superadmin.restaurants', ['month' => '2026-11']))->assertUnprocessable();
        $this->assertSame($before, [CloudSubscription::count(), CloudInvoice::count(), CloudPayment::count(), AuditLog::count()]);
    }

    public function test_restaurant_directory_is_bounded_and_preserves_filters_on_next_page(): void
    {
        foreach (range(1, 23) as $number) $this->restaurant(null, ['name' => sprintf('Bounded Restaurant %02d', $number)]);
        $this->actingAs($this->user());
        DB::enableQueryLog();
        $response = $this->get(route('superadmin.restaurants', ['q' => 'Bounded', 'month' => '2026-09']));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $response->assertOk()->assertViewHas('restaurants', function ($items) {
            $this->assertCount(20, $items->items());
            $this->assertSame(23, $items->total());
            $this->assertStringContainsString('month=2026-09', $items->nextPageUrl());
            return true;
        });
        $this->assertLessThan(25, count($queries), 'Directory queries should remain bounded, not grow per restaurant.');
    }
}
