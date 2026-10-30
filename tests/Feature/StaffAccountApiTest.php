<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role, string $username): User
    {
        return User::create(['name' => $username, 'username' => $username, 'role_id' => Role::firstOrCreate(['name' => $role], ['display_name' => ucfirst($role)])->id, 'password' => 'test-password', 'pin' => '1234', 'is_active' => true]);
    }

    public function test_mobile_update_uses_shared_validation_preserves_blanks_and_hides_secrets(): void
    {
        $admin = $this->staff('admin', 'api.admin');
        $user = $this->staff('counter', 'api.counter');
        $before = $user->only(['password', 'pin']);
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/staff/'.$user->id, ['role_id' => $user->role_id, 'name' => 'Edited Counter', 'username' => 'edited.counter', 'email' => '', 'mobile' => '', 'password' => '', 'pin' => ''])
            ->assertOk()->assertJsonPath('user.name', 'Edited Counter')->assertJsonPath('message', 'Staff account updated.')
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.pin')->assertJsonMissingPath('user.remember_token');
        $this->assertSame($before, $user->fresh()->only(['password', 'pin']));
        $this->putJson('/api/v1/admin/staff/'.$user->id, ['role_id' => $user->role_id, 'name' => 'Counter', 'username' => 'edited.counter', 'password' => 'new-test-password', 'clear_pin' => true])->assertOk();
        $this->assertTrue(Hash::check('new-test-password', $user->fresh()->password));
        $this->assertNull($user->fresh()->pin);
    }

    public function test_mobile_invalid_update_returns_field_errors_without_mutating(): void
    {
        $admin = $this->staff('admin', 'api.admin');
        $user = $this->staff('counter', 'api.counter');
        $before = $user->fresh()->getAttributes();
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/staff/'.$user->id, ['role_id' => 99999, 'name' => '', 'username' => 'bad name', 'password' => 'short', 'pin' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors(['role_id', 'name', 'username', 'password', 'pin']);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_mobile_delete_revokes_tokens_archives_account_and_disappears_from_team(): void
    {
        $admin = $this->staff('admin', 'api.admin');
        $user = $this->staff('counter', 'api.counter');
        $user->createToken('test phone');
        Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/admin/staff/'.$user->id)->assertOk()->assertJsonPath('message', 'Staff account deleted. Past orders, bills and audit records have been kept.');
        $this->assertSoftDeleted($user);
        $this->assertSame(0, $user->tokens()->count());
        $this->getJson('/api/v1/admin/team')->assertOk()->assertJsonMissing(['username' => 'api.counter']);
        $this->deleteJson('/api/v1/admin/staff/'.$user->id)->assertNotFound();
        $this->assertSame(1, AuditLog::where('action', 'user.deleted')->count());
    }

    public function test_mobile_self_delete_and_self_role_change_are_rejected(): void
    {
        $admin = $this->staff('admin', 'api.admin');
        $counter = $this->staff('counter', 'api.counter');
        Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/admin/staff/'.$admin->id)->assertUnprocessable()->assertJsonValidationErrors('staff');
        $this->putJson('/api/v1/admin/staff/'.$admin->id, ['name' => $admin->name, 'username' => $admin->username, 'role_id' => $counter->role_id])->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_mobile_guest_and_non_admin_cannot_edit_delete_or_toggle(): void
    {
        $admin = $this->staff('admin', 'api.admin');
        $counter = $this->staff('counter', 'api.counter');
        $this->putJson('/api/v1/admin/staff/'.$admin->id, [])->assertUnauthorized();
        $this->deleteJson('/api/v1/admin/staff/'.$admin->id)->assertUnauthorized();
        Sanctum::actingAs($counter);
        $this->putJson('/api/v1/admin/staff/'.$admin->id, [])->assertForbidden();
        $this->deleteJson('/api/v1/admin/staff/'.$admin->id)->assertForbidden();
        $this->postJson('/api/v1/admin/staff/'.$admin->id.'/toggle')->assertForbidden();
    }

    public function test_platform_accounts_are_protected_from_mobile_staff_actions(): void
    {
        $admin = $this->staff('admin', 'api.admin');
        $platform = $this->staff('superadmin', 'api.platform');
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/staff/'.$platform->id, [])->assertForbidden();
        $this->deleteJson('/api/v1/admin/staff/'.$platform->id)->assertForbidden();
        $this->postJson('/api/v1/admin/staff/'.$platform->id.'/toggle')->assertForbidden();
    }
}
