<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Notification};
use Tests\TestCase;

class StaffAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'admin', string $username = 'admin.test'): User
    {
        return User::create([
            'name' => $username, 'username' => $username,
            'role_id' => Role::firstOrCreate(['name' => $role], ['display_name' => ucfirst($role)])->id,
            'password' => 'original-password', 'pin' => '1234', 'is_active' => true,
        ]);
    }

    private function profile(User $user, array $overrides = []): array
    {
        return array_merge($user->only(['name', 'username', 'role_id', 'email', 'mobile']), $overrides);
    }

    public function test_create_button_creates_valid_staff_and_password_and_pin_both_allow_login(): void
    {
        $admin = $this->staff();
        $role = Role::firstOrCreate(['name' => 'counter'], ['display_name' => 'Counter']);
        $this->actingAs($admin)->from(route('admin.team'))->post(route('admin.staff.store'), [
            'role_id' => $role->id, 'name' => ' QA Staff ', 'username' => ' qa.staff ',
            'email' => ' QA.STAFF@example.test ', 'mobile' => ' 1234567890 ',
            'password' => 'test-only-password', 'pin' => '4567',
        ])->assertRedirect(route('admin.team'))->assertSessionHasNoErrors();
        $staff = User::where('username', 'qa.staff')->firstOrFail();
        $this->assertSame('QA Staff', $staff->name);
        $this->assertSame('qa.staff@example.test', $staff->email);
        $this->assertTrue(Hash::check('test-only-password', $staff->password));
        $this->assertTrue(Hash::check('4567', $staff->pin));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'entity_id' => $staff->id]);
        foreach (['test-only-password', '4567'] as $credential) {
            auth()->logout();
            $this->post(route('login.store'), ['username' => 'qa.staff', 'credential' => $credential])->assertRedirect('/counter');
            $this->assertAuthenticatedAs($staff);
        }
    }

    public function test_disable_enable_and_self_disable_guard_preserve_account_details(): void
    {
        $admin = $this->staff();
        $staff = $this->staff('counter', 'counter.test');
        $before = $staff->only(['username', 'password', 'pin', 'role_id']);
        $this->actingAs($admin)->from(route('admin.team'))->post(route('admin.staff.toggle', $staff))->assertRedirect(route('admin.team'));
        $this->assertFalse($staff->fresh()->is_active);
        auth()->logout();
        $this->post(route('login.store'), ['username' => 'counter.test', 'credential' => 'original-password'])->assertSessionHasErrors('username');
        $this->actingAs($admin)->post(route('admin.staff.toggle', $staff))->assertRedirect();
        $this->assertTrue($staff->fresh()->is_active);
        $this->assertSame($before, $staff->fresh()->only(['username', 'password', 'pin', 'role_id']));
        $this->post(route('admin.staff.toggle', $admin))->assertUnprocessable();
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame(2, AuditLog::where('action', 'user.toggled')->count());
    }

    public function test_edit_and_cancel_navigation_are_read_only_and_credentials_are_never_prefilled(): void
    {
        $admin = $this->staff();
        $staff = $this->staff('counter', 'counter.test');
        $before = $staff->fresh()->getAttributes();
        $response = $this->actingAs($admin)->get(route('admin.staff.edit', $staff))->assertOk();
        $response->assertSee('href="'.route('admin.team').'">Cancel</a>', false);
        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        foreach (['password', 'pin'] as $field) {
            $input = $xpath->query('//input[@name="'.$field.'"]')->item(0);
            $this->assertNotNull($input);
            $this->assertSame('', $input->getAttribute('value'));
            $this->assertFalse($input->hasAttribute('required'));
        }
        $this->get(route('admin.team'))->assertOk();
        $this->assertSame($before, $staff->fresh()->getAttributes());
        $this->assertSame(0, AuditLog::whereIn('action', ['user.updated', 'user.deleted'])->count());
    }

    public function test_get_request_cannot_update_or_delete_staff_and_repeated_delete_is_safe(): void
    {
        $admin = $this->staff();
        $staff = $this->staff('counter', 'counter.test');
        $this->actingAs($admin)->get(route('admin.staff.destroy', $staff))->assertStatus(405);
        $this->assertNotNull(User::find($staff->id));
        $this->delete(route('admin.staff.destroy', $staff))->assertRedirect(route('admin.team'));
        $this->delete(route('admin.staff.destroy', $staff))->assertNotFound();
        $this->assertSame(1, AuditLog::where('action', 'user.deleted')->count());
    }

    public function test_deleted_identity_cannot_silently_be_reused_for_a_new_account(): void
    {
        $admin = $this->staff();
        $staff = $this->staff('counter', 'counter.test');
        $this->actingAs($admin)->delete(route('admin.staff.destroy', $staff))->assertRedirect();
        $this->post(route('admin.staff.store'), $this->profile($staff, ['password' => 'replacement-password']))->assertSessionHasErrors('username');
        $this->assertSoftDeleted($staff);
    }

    public function test_team_shows_edit_delete_and_custom_confirmation_but_no_self_delete(): void
    {
        $admin = $this->staff();
        $counter = $this->staff('counter', 'counter.test');
        $response = $this->actingAs($admin)->get(route('admin.team'))->assertOk();
        $response->assertSee(route('admin.staff.edit', $counter), false)
            ->assertSee(route('admin.staff.destroy', $counter), false)
            ->assertSee('data-confirm-title="Delete staff account?"', false)
            ->assertDontSee('aria-label="Delete admin.test"', false);
        $this->get(route('admin.staff.edit', $counter))->assertOk()->assertSee('Edit staff member')->assertSee('Save changes')->assertSee('value="counter.test"', false);
    }

    public function test_profile_edits_keep_blank_credentials_and_record_no_secrets(): void
    {
        $admin = $this->staff();
        $counter = $this->staff('counter', 'counter.test');
        $before = $counter->only(['password', 'pin']);
        $this->actingAs($admin)->put(route('admin.staff.update', $counter), $this->profile($counter, [
            'name' => 'Updated counter', 'mobile' => '1234567890', 'password' => '', 'pin' => '',
        ]))->assertRedirect(route('admin.team'))->assertSessionHasNoErrors();
        $this->assertSame('Updated counter', $counter->fresh()->name);
        $this->assertSame($before, $counter->fresh()->only(['password', 'pin']));
        $audit = AuditLog::where('action', 'user.updated')->firstOrFail();
        $this->assertArrayNotHasKey('password', $audit->new_values);
        $this->assertArrayNotHasKey('pin', $audit->new_values);
    }

    public function test_credentials_are_hashed_and_old_tokens_and_sessions_revoked(): void
    {
        $admin = $this->staff();
        $counter = $this->staff('counter', 'counter.test');
        $counter->createToken('test device');
        DB::table('sessions')->insert(['id' => 'old-staff-session', 'user_id' => $counter->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin)->put(route('admin.staff.update', $counter), $this->profile($counter, ['password' => 'replacement-password', 'pin' => '9876']))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('replacement-password', $counter->fresh()->password));
        $this->assertTrue(Hash::check('9876', $counter->fresh()->pin));
        $this->assertSame(0, $counter->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'old-staff-session']);
        $this->actingAs($admin)->put(route('admin.staff.update', $counter), $this->profile($counter, ['clear_pin' => 1]))->assertSessionHasNoErrors();
        $this->assertNull($counter->fresh()->pin);
    }

    public function test_invalid_fields_duplicate_identity_and_platform_roles_are_rejected(): void
    {
        $admin = $this->staff();
        $counter = $this->staff('counter', 'counter.test');
        $platform = $this->staff('superadmin', 'platform.test');
        $before = $counter->fresh()->getAttributes();
        $this->actingAs($admin)->put(route('admin.staff.update', $counter), $this->profile($counter, [
            'username' => $admin->username, 'role_id' => $platform->role_id, 'password' => 'short', 'pin' => 'abc', 'email' => 'not-an-email',
        ]))->assertSessionHasErrors(['username', 'role_id', 'password', 'pin', 'email']);
        $this->assertNull(session()->getOldInput('pin'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertSame($before, $counter->fresh()->getAttributes());
        $this->get(route('admin.staff.edit', $platform))->assertForbidden();
        $this->put(route('admin.staff.update', $platform), $this->profile($platform))->assertForbidden();
        $this->delete(route('admin.staff.destroy', $platform))->assertForbidden();
    }

    public function test_delete_removes_access_but_preserves_financial_history_and_audit_actor(): void
    {
        $admin = $this->staff();
        $counter = $this->staff('counter', 'counter.test');
        $counter->createToken('old device');
        $tableId = DB::table('dining_tables')->insertGetId(['table_code' => 'T1', 'table_name' => 'Table 1']);
        $sessionId = DB::table('table_sessions')->insertGetId(['session_code' => 'TEST-VISIT', 'dining_table_id' => $tableId, 'opened_at' => now(), 'opened_by' => $counter->id]);
        $billId = DB::table('bills')->insertGetId(['bill_number' => 'B1', 'table_session_id' => $sessionId, 'subtotal' => 100, 'tax_amount' => 0, 'grand_total' => 100, 'generated_by' => $counter->id, 'generated_at' => now()]);
        DB::table('payments')->insert(['bill_id' => $billId, 'payment_number' => 'P1', 'amount' => 100, 'received_amount' => 100, 'change_amount' => 0, 'received_by' => $counter->id, 'paid_at' => now()]);
        $previousAudit = AuditLog::create(['user_id' => $counter->id, 'action' => 'payment.recorded', 'created_at' => now()]);
        $this->actingAs($admin)->delete(route('admin.staff.destroy', $counter))->assertRedirect(route('admin.team'))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($counter);
        $this->assertNull(User::find($counter->id));
        $this->assertFalse(User::withTrashed()->findOrFail($counter->id)->is_active);
        $this->assertDatabaseHas('bills', ['id' => $billId, 'generated_by' => $counter->id]);
        $this->assertDatabaseHas('payments', ['bill_id' => $billId, 'received_by' => $counter->id]);
        $this->assertSame($counter->id, $previousAudit->fresh()->user->id);
        $this->assertSame(0, $counter->tokens()->count());
        $this->get(route('admin.team'))->assertDontSee('aria-label="Edit counter.test"', false);
        $this->get(route('admin.staff.edit', $counter))->assertNotFound();
        $this->post(route('admin.staff.toggle', $counter))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deleted', 'entity_id' => $counter->id]);
        auth()->logout();
        $this->post(route('login.store'), ['username' => 'counter.test', 'credential' => 'original-password'])->assertSessionHasErrors('username');
    }

    public function test_self_deletion_and_self_demotion_are_blocked_but_profile_edit_is_allowed(): void
    {
        $admin = $this->staff();
        $counter = $this->staff('counter', 'counter.test');
        $this->actingAs($admin)->delete(route('admin.staff.destroy', $admin))->assertSessionHasErrors('staff');
        $this->put(route('admin.staff.update', $admin), $this->profile($admin, ['role_id' => $counter->role_id]))->assertSessionHasErrors('role_id');
        $this->put(route('admin.staff.update', $admin), $this->profile($admin, ['name' => 'Updated administrator']))->assertSessionHasNoErrors();
        $this->assertSame('Updated administrator', $admin->fresh()->name);
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_another_administrator_can_be_demoted_or_deleted(): void
    {
        $admin = $this->staff();
        $other = $this->staff('admin', 'other.admin');
        $counter = $this->staff('counter', 'counter.test');
        $this->actingAs($admin)->put(route('admin.staff.update', $other), $this->profile($other, ['role_id' => $counter->role_id]))->assertSessionHasNoErrors();
        $this->assertTrue($other->fresh()->hasRole('counter'));
        $this->delete(route('admin.staff.destroy', $other))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($other);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_non_admin_and_guest_cannot_manage_staff(): void
    {
        $admin = $this->staff();
        $counter = $this->staff('counter', 'counter.test');
        $this->get(route('admin.staff.edit', $admin))->assertRedirect(route('login'));
        $this->put(route('admin.staff.update', $admin), $this->profile($admin))->assertRedirect(route('login'));
        $this->delete(route('admin.staff.destroy', $admin))->assertRedirect(route('login'));
        $this->actingAs($counter)->get(route('admin.staff.edit', $admin))->assertForbidden();
        $this->put(route('admin.staff.update', $admin), $this->profile($admin))->assertForbidden();
        $this->delete(route('admin.staff.destroy', $admin))->assertForbidden();
    }

    public function test_email_change_requires_fresh_verification_when_enabled(): void
    {
        Notification::fake();
        $admin = $this->staff();
        $other = $this->staff('admin', 'other.admin');
        $admin->forceFill(['email' => 'admin@example.test', 'email_verified_at' => now()])->save();
        $other->forceFill(['email' => 'old@example.test', 'email_verified_at' => now()])->save();
        config(['tableplay.require_privileged_email_verification' => true]);
        $this->actingAs($admin)->put(route('admin.staff.update', $other), $this->profile($other, ['email' => 'new@example.test']))->assertSessionHasNoErrors();
        $this->assertNull($other->fresh()->email_verified_at);
        Notification::assertSentTo($other, \Illuminate\Auth\Notifications\VerifyEmail::class);
    }
}
