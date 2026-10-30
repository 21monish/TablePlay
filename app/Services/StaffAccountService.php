<?php

namespace App\Services;

use App\Models\{Role, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class StaffAccountService
{
    private const ROLES = ['admin', 'counter', 'kitchen', 'waiter'];
    private const PROFILE = ['role_id', 'name', 'username', 'email', 'mobile', 'is_active'];

    public function update(Request $request, User $user, AuditService $audit): array
    {
        $this->assertStaff($user);
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'username' => trim((string) $request->input('username')),
            'email' => $request->filled('email') ? strtolower(trim((string) $request->input('email'))) : null,
            'mobile' => $request->filled('mobile') ? trim((string) $request->input('mobile')) : null,
        ]);
        $role = Role::find($request->input('role_id'));
        $data = $request->validate([
            'role_id' => ['required', Rule::exists('roles', 'id')->where(fn ($query) => $query->whereIn('name', self::ROLES))],
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users')->ignore($user)],
            'email' => [Rule::requiredIf(config('tableplay.require_privileged_email_verification') && $role?->name === 'admin'), 'nullable', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user)],
            'mobile' => 'nullable|string|max:30',
            'password' => 'nullable|string|min:8|max:255',
            'pin' => 'nullable|digits_between:4,12',
            'clear_pin' => 'nullable|boolean',
        ]);
        $verificationNeeded = DB::transaction(function () use ($request, $user, $audit, $data) {
            $user = $this->lockedStaff($request, $user);
            if ($user->is($request->user()) && (int) $data['role_id'] !== $user->role_id) {
                throw ValidationException::withMessages(['role_id' => 'Ask another administrator to change your role.']);
            }
            if ($user->hasRole('admin') && $user->is_active && (int) $data['role_id'] !== $user->role_id) {
                $this->assertAnotherAdmin($user);
            }
            $old = $user->only(self::PROFILE);
            $credentialsChanged = $request->filled('password') || $request->filled('pin') || $request->boolean('clear_pin');
            $profile = collect($data)->only(['role_id', 'name', 'username', 'email', 'mobile'])->all();
            $emailChanged = $user->email !== $profile['email'];
            $roleChanged = $user->role_id !== (int) $profile['role_id'];
            $user->fill($profile);
            if ($emailChanged) $user->email_verified_at = null;
            if ($request->filled('password')) $user->password = $data['password'];
            if ($request->filled('pin')) $user->pin = $data['pin'];
            if ($request->boolean('clear_pin')) $user->pin = null;
            if ($credentialsChanged || $roleChanged || $emailChanged) {
                $user->remember_token = Str::random(60);
                $user->tokens()->delete();
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
            $user->save();
            $audit->record($request, 'user.updated', $user, $old, $user->only(self::PROFILE));
            return $user->load('role')->requiresEmailVerification() && !$user->hasVerifiedEmail() && ($emailChanged || $roleChanged);
        });
        if ($verificationNeeded) {
            try {
                $user->fresh()->sendEmailVerificationNotification();
            } catch (Throwable $exception) {
                report($exception);
                return ['user' => $user->fresh('role'), 'warning' => 'Staff account updated, but the verification email could not be sent. Check the mail settings.'];
            }
        }
        return ['user' => $user->fresh('role'), 'message' => 'Staff account updated.'];
    }

    public function destroy(Request $request, User $user, AuditService $audit): array
    {
        DB::transaction(function () use ($request, $user, $audit) {
            $user = $this->lockedStaff($request, $user);
            if ($user->is($request->user())) {
                throw ValidationException::withMessages(['staff' => 'You cannot delete your own account.']);
            }
            if ($user->hasRole('admin') && $user->is_active) $this->assertAnotherAdmin($user);
            $old = $user->only(self::PROFILE);
            $user->forceFill(['is_active' => false, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            $user->delete();
            $audit->record($request, 'user.deleted', $user, $old, ['is_active' => false, 'deleted_at' => $user->deleted_at->toIso8601String()]);
        });
        return ['message' => 'Staff account deleted. Past orders, bills and audit records have been kept.'];
    }

    private function assertStaff(User $user): void
    {
        abort_unless($user->load('role')->hasRole(...self::ROLES), 403, 'Platform accounts cannot be managed from a restaurant workspace.');
    }

    private function lockedStaff(Request $request, User $user): User
    {
        // Serialize administrator changes, including concurrent demotion/deletion.
        $admins = User::whereHas('role', fn ($query) => $query->where('name', 'admin'))->orderBy('id')->lockForUpdate()->get();
        abort_unless($admins->contains(fn ($admin) => $admin->is($request->user()) && $admin->is_active), 403);
        $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $this->assertStaff($user);
        return $user;
    }

    private function assertAnotherAdmin(User $user): void
    {
        if (!User::whereKeyNot($user->id)->where('is_active', true)->whereHas('role', fn ($query) => $query->where('name', 'admin'))->exists()) {
            throw ValidationException::withMessages(['staff' => 'Keep at least one active administrator account.']);
        }
    }
}
