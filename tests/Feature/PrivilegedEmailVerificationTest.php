<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PrivilegedEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tableplay.require_privileged_email_verification' => true]);
    }

    public function test_privileged_user_can_sign_in_by_email_but_is_held_at_verification_screen(): void
    {
        $user = $this->user('superadmin', 'owner@example.com');

        $this->post('/login', ['username' => 'OWNER@example.com', 'credential' => 'Strong@123'])
            ->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs($user);
        $this->get('/superadmin')->assertRedirect(route('verification.notice'));
    }

    public function test_signed_link_verifies_privileged_email_and_allows_workspace_access(): void
    {
        Event::fake([Verified::class]);
        $user = $this->user('superadmin', 'owner@example.com');
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect('/superadmin');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_mobile_admin_login_is_rejected_until_email_is_verified(): void
    {
        $user = $this->user('admin', 'admin@example.com');

        $this->postJson('/api/v1/auth/login', [
            'username' => 'admin@example.com',
            'password' => 'Strong@123',
            'device_name' => 'Admin phone',
        ])->assertForbidden()->assertJsonPath('code', 'email_verification_required');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $user->markEmailAsVerified();
        $this->postJson('/api/v1/auth/login', [
            'username' => 'admin@example.com',
            'password' => 'Strong@123',
            'device_name' => 'Admin phone',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_waiter_pin_login_does_not_require_email_verification(): void
    {
        $user = $this->user('waiter');
        $user->update(['pin' => '2468']);

        $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'pin' => '2468',
            'device_name' => 'Waiter phone',
        ])->assertOk()->assertJsonPath('user.role.name', 'waiter');
    }

    private function user(string $roleName, ?string $email = null): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => ucfirst($roleName)]);

        return User::create([
            'role_id' => $role->id,
            'name' => ucfirst($roleName),
            'username' => $roleName,
            'email' => $email,
            'password' => 'Strong@123',
            'is_active' => true,
        ]);
    }
}
