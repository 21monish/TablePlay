<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountPasswordReset;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $attributes = []): User
    {
        $role = Role::firstOrCreate(['name' => 'superadmin'], ['display_name' => 'Platform owner']);

        return User::factory()->create(['role_id' => $role->id, 'username' => 'superadmin', 'email' => 'owner@example.com', ...$attributes]);
    }

    public function test_recovery_pages_have_csrf_visibility_controls_and_do_not_cache_tokens(): void
    {
        $this->get('/login')->assertOk()->assertSee('Forgot your password?');
        $this->get('/forgot-password')->assertOk()->assertSee('name="_token"', false);
        $this->get('/reset-password/example?email=owner%40example.com')->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('data-secret-toggle', false)->assertSee('owner@example.com');
    }

    public function test_active_account_gets_single_use_hashed_token_and_canonical_link(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp', 'app.url' => 'https://tableplay.example']);
        $user = $this->account();
        $this->withHeader('Host', 'untrusted.example')->post('/forgot-password', ['email' => ' OWNER@EXAMPLE.COM '])->assertRedirect(route('password.request'))->assertSessionHas('status');
        Notification::assertSentTo($user, AccountPasswordReset::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringStartsWith('https://tableplay.example/reset-password/', $url);
            $this->assertStringNotContainsString('untrusted.example', $url);
            $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
            $this->assertNotSame($notification->token, $stored);
            $this->assertTrue(Hash::check($notification->token, $stored));

            return true;
        });
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentToTimes($user, AccountPasswordReset::class, 1);
    }

    public function test_unknown_and_disabled_accounts_receive_same_generic_result_without_mail(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $this->account(['is_active' => false]);
        $this->post('/forgot-password', ['email' => 'owner@example.com'])->assertSessionHas('status');
        $disabled = session('status');
        $this->post('/forgot-password', ['email' => 'unknown@example.com'])->assertSessionHas('status', $disabled);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_mail_disabled_does_not_log_or_issue_a_recovery_token(): void
    {
        $this->account();
        config(['mail.default' => 'log']);
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'owner@example.com'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_failover_logging_is_not_allowed_to_leak_reset_tokens(): void
    {
        $this->account();
        config(['mail.default' => 'failover']);
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'owner@example.com'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_array_email_and_overlong_multibyte_password_are_rejected(): void
    {
        $this->post('/forgot-password', ['email' => ['not-an-email']])->assertSessionHasErrors('email');
        $password = str_repeat('é', 35).'Ab1!';
        $this->post('/reset-password', ['email' => 'owner@example.com', 'token' => 'test', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
    }

    public function test_recovery_forms_safely_render_malformed_old_email_input(): void
    {
        $this->withSession(['_old_input' => ['email' => ['unexpected']]])->get('/forgot-password')->assertOk();
        $this->withSession(['_old_input' => ['email' => ['unexpected']]])->get('/reset-password/test')->assertOk();
    }

    public function test_reset_revokes_sessions_tokens_and_prevents_replay_without_changing_verification_or_pin(): void
    {
        $user = $this->account(['email_verified_at' => now(), 'pin' => '2580']);
        $verification = $user->email_verified_at->toDateTimeString();
        $user->createToken('old-staff-session');
        DB::table('sessions')->insert(['id' => 'old-web-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'NewSecret!12345', 'password_confirmation' => 'NewSecret!12345'];
        $this->post('/reset-password', $payload)->assertRedirect('/login')->assertSessionHas('status');
        $user->refresh();
        $this->assertTrue(Hash::check('NewSecret!12345', $user->password));
        $this->assertTrue(Hash::check('2580', $user->pin));
        $this->assertSame($verification, $user->email_verified_at->toDateTimeString());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-web-session']);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.password_reset', 'entity_id' => $user->id]);
        $this->assertGuest();
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
    }

    public function test_expired_token_and_disabled_account_cannot_reset(): void
    {
        $user = $this->account();
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'NewSecret!12345', 'password_confirmation' => 'NewSecret!12345'];
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->travelBack();
        $payload['token'] = Password::createToken($user);
        $user->update(['is_active' => false]);
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('NewSecret!12345', $user->fresh()->password));
    }

    public function test_validation_does_not_flash_passwords_or_recovery_tokens(): void
    {
        $this->from('/reset-password/example')->post('/reset-password', ['email' => 'owner@example.com', 'token' => 'private-reset-token', 'password' => 'weak', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertSame(['email' => 'owner@example.com'], session('_old_input'));
    }

    public function test_recovery_request_is_rate_limited(): void
    {
        config(['mail.default' => 'smtp']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => 'unknown@example.com'])->assertRedirect();
        }
        $this->post('/forgot-password', ['email' => 'unknown@example.com'])->assertStatus(429);
    }

    public function test_reseeding_preserves_existing_superadmin_credentials_profile_and_suspension(): void
    {
        $user = $this->account(['name' => 'Existing owner', 'is_active' => false, 'email_verified_at' => now(), 'password' => 'RecoveredSecret!123']);
        $before = $user->fresh()->getRawOriginal();
        config(['tableplay.superadmin.name' => 'Stale bootstrap', 'tableplay.superadmin.email' => 'different@example.com', 'tableplay.superadmin.password' => 'OldBootstrap!123']);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        config(['tableplay.superadmin.password' => '']);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }
}
