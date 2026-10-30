<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivilegedEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Sanctum authenticates both staff users and customer devices. Device
        // tokens must not reach staff-only account methods or workspaces.
        abort_if($user !== null && ! ($user instanceof User), 403, 'A staff account is required.');

        if (! $user || ! $user->requiresEmailVerification() || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Verify your email address before accessing this workspace.',
                'code' => 'email_verification_required',
            ], 403);
        }

        return redirect()->route('verification.notice');
    }
}
