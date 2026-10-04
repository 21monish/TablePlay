<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\CloudLicenseKey;
use App\Models\CloudSubscription;
use App\Models\User;
use App\Notifications\TrialWelcomeNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SelfServiceTrialTest extends TestCase
{
    use RefreshDatabase;

    protected bool $withTestLicense = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'tableplay.mode' => 'cloud',
            'tableplay.require_privileged_email_verification' => true,
        ]);
    }

    public function test_owner_can_register_but_trial_waits_for_email_verification(): void
    {
        Notification::fake();

        $this->post(route('trial.store'), $this->registration())
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('restaurant_owner'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('cloud_restaurants', [
            'owner_user_id' => $user->id,
            'name' => 'Ganesh Restaurant',
            'owner_phone' => '+919876543210',
            'city' => 'Pune',
        ]);
        $this->assertDatabaseCount('cloud_subscriptions', 0);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_signed_verification_activates_one_idempotent_fourteen_day_trial(): void
    {
        Notification::fake();
        $this->post(route('trial.store'), $this->registration());
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('account.index'));
        $this->get(route('account.index'))->assertOk()->assertSee('Ganesh Restaurant')->assertSee('14 days');

        $subscription = CloudSubscription::with('plan')->firstOrFail();
        $this->assertSame('trial', $subscription->status);
        $this->assertSame(14, $subscription->plan->trial_days);
        $this->assertSame(3, $subscription->plan->max_paired_tables);
        $this->assertEquals(14, $subscription->starts_at->diffInDays($subscription->expires_at));
        $this->get(route('account.index'))->assertOk();
        $this->assertDatabaseCount('cloud_subscriptions', 1);
        $this->assertNotNull($user->cloudRestaurant->fresh()->welcome_email_sent_at);
        Notification::assertSentToTimes($user, TrialWelcomeNotification::class, 1);

        $this->get($url)->assertRedirect(route('account.index'));
        Notification::assertSentToTimes($user, TrialWelcomeNotification::class, 1);
    }

    public function test_verified_owner_can_rotate_a_one_time_activation_key(): void
    {
        Notification::fake();
        $this->post(route('trial.store'), $this->registration());
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $user->markEmailAsVerified();

        $this->actingAs($user)->post(route('account.activation-key'))
            ->assertRedirect()
            ->assertSessionHas('issued_license_key');
        $first = CloudLicenseKey::firstOrFail();
        $this->assertTrue($first->is_active);

        $this->post(route('account.activation-key'))->assertSessionHas('issued_license_key');
        $this->assertFalse($first->fresh()->is_active);
        $this->assertSame(1, CloudLicenseKey::where('is_active', true)->count());
        $this->assertDatabaseCount('cloud_license_keys', 2);
    }

    public function test_registration_rejects_duplicate_identity_weak_password_and_missing_consent(): void
    {
        Notification::fake();
        $this->post(route('trial.store'), $this->registration())->assertRedirect();
        auth()->logout();

        $invalid = $this->registration([
            'restaurant_name' => 'Second Restaurant',
            'password' => 'weak',
            'password_confirmation' => 'weak',
            'terms' => null,
            'privacy' => null,
        ]);
        $this->post(route('trial.store'), $invalid)
            ->assertSessionHasErrors(['email', 'mobile', 'password', 'terms', 'privacy']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('cloud_restaurants', 1);
    }

    public function test_verified_owner_sees_current_verified_downloads_and_can_download_installer(): void
    {
        Notification::fake();
        Storage::fake('updates');
        config([
            'app_updates.installer.disk' => 'updates',
            'app_updates.installer.path' => 'server-windows/stable/TablePlay-Setup.exe',
            'app_updates.installer.version' => '2.5.6',
            'app_updates.installer.sha256' => str_repeat('a', 64),
        ]);

        Storage::disk('updates')->put('server-windows/stable/TablePlay-Setup.exe', 'verified-installer');
        Storage::disk('updates')->put('staff/2.5.6/staff.zip', 'staff-package');
        $release = AppRelease::create([
            'app' => 'staff',
            'platform' => 'windows',
            'channel' => 'stable',
            'version' => '2.5.6',
            'file_path' => 'staff/2.5.6/staff.zip',
            'original_filename' => 'staff.zip',
            'mime_type' => 'application/zip',
            'file_size' => 13,
            'sha256' => str_repeat('b', 64),
            'verified_at' => now(),
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->post(route('trial.store'), $this->registration());
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $user->markEmailAsVerified();

        $this->actingAs($user)->get(route('account.index'))
            ->assertOk()
            ->assertSee('Verified downloads')
            ->assertSee('TablePlay Setup')
            ->assertSee('2.5.6')
            ->assertSee(str_repeat('a', 64))
            ->assertSee($release->version)
            ->assertSee(route('account.installer.download'), false)
            ->assertSee(route('api.app-updates.download', ['target' => 'staff-windows']), false);

        $this->get(route('account.installer.download'))
            ->assertOk()
            ->assertDownload('TablePlay-Setup-v2.5.6.exe')
            ->assertHeader('x-checksum-sha256', str_repeat('a', 64))
            ->assertHeader('x-app-version', '2.5.6');
    }

    public function test_verified_owner_is_redirected_to_a_configured_github_installer(): void
    {
        Notification::fake();
        config([
            'app_updates.installer.path' => 'server-windows/stable/TablePlay-Setup.exe',
            'app_updates.installer.url' => 'https://github.com/21monish/TablePlay/releases/download/v2.5.6/TablePlay-Setup-v2.5.6.exe',
            'app_updates.installer.version' => '2.5.6',
            'app_updates.installer.size' => 466790947,
            'app_updates.installer.sha256' => str_repeat('a', 64),
        ]);

        $this->post(route('trial.store'), $this->registration());
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $user->markEmailAsVerified();

        $this->actingAs($user)->get(route('account.index'))
            ->assertOk()
            ->assertSee('445.2 MB')
            ->assertSee(str_repeat('a', 64));

        $this->get(route('account.installer.download'))
            ->assertRedirect('https://github.com/21monish/TablePlay/releases/download/v2.5.6/TablePlay-Setup-v2.5.6.exe');
    }

    public function test_installer_rejects_untrusted_external_urls(): void
    {
        Notification::fake();
        config([
            'app_updates.installer.path' => 'server-windows/stable/TablePlay-Setup.exe',
            'app_updates.installer.url' => 'https://example.test/TablePlay-Setup.exe',
            'app_updates.installer.version' => '2.5.6',
            'app_updates.installer.size' => 466790947,
            'app_updates.installer.sha256' => str_repeat('a', 64),
        ]);

        $this->post(route('trial.store'), $this->registration());
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $user->markEmailAsVerified();

        $this->actingAs($user)->get(route('account.installer.download'))->assertNotFound();
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'restaurant_name' => 'Ganesh Restaurant',
            'owner_name' => 'Ganesh Owner',
            'email' => 'owner@example.test',
            'mobile' => '+91 98765 43210',
            'city' => 'Pune',
            'password' => 'Ganesh@123',
            'password_confirmation' => 'Ganesh@123',
            'terms' => '1',
            'privacy' => '1',
            'website' => '',
        ], $overrides);
    }
}
