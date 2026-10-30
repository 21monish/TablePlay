<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Role, User};
use App\Services\{AuditService, StaffAccountService};
use Illuminate\Http\Request;

class StaffAccountController extends Controller
{
    public function edit(User $user)
    {
        $roles = ['admin', 'counter', 'kitchen', 'waiter'];
        abort_unless($user->load('role')->hasRole(...$roles), 403, 'Platform accounts cannot be managed from a restaurant workspace.');
        return view('admin.team', [
            'editUser' => $user,
            'roles' => Role::whereIn('name', $roles)->orderBy('name')->get(),
            'users' => User::with('role')->whereHas('role', fn ($query) => $query->whereIn('name', $roles))->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user, AuditService $audit, StaffAccountService $staff)
    {
        $result = $staff->update($request, $user, $audit);
        return redirect()->route('admin.team')->with(
            isset($result['warning']) ? 'warning' : 'status',
            $result['warning'] ?? $result['message'],
        );
    }

    public function destroy(Request $request, User $user, AuditService $audit, StaffAccountService $staff)
    {
        $result = $staff->destroy($request, $user, $audit);
        return redirect()->route('admin.team')->with('status', $result['message']);
    }
}
