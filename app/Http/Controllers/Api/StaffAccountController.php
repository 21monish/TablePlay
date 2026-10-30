<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\{AuditService, StaffAccountService};
use Illuminate\Http\Request;

class StaffAccountController extends Controller
{
    public function update(Request $request, User $user, AuditService $audit, StaffAccountService $staff): array
    {
        return $staff->update($request, $user, $audit);
    }

    public function destroy(Request $request, User $user, AuditService $audit, StaffAccountService $staff): array
    {
        return $staff->destroy($request, $user, $audit);
    }
}
