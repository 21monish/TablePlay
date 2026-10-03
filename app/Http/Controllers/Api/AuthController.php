<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): array
    {
        $request->merge([
            'username' => trim((string) $request->input('username')),
            'pin' => $request->filled('pin') ? trim((string) $request->input('pin')) : null,
            'device_name' => $request->filled('device_name') ? trim((string) $request->input('device_name')) : null,
        ]);

        if ($request->filled('password') && $request->filled('pin')) {
            throw ValidationException::withMessages([
                'password' => 'Send either a password or a PIN, not both.',
                'pin' => 'Send either a password or a PIN, not both.',
            ]);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'required_without:pin', 'string', 'max:255'],
            'pin' => ['nullable', 'required_without:password', 'regex:/^\d{4,12}$/'],
            'device_name' => ['nullable', 'string', 'min:2', 'max:100'],
        ]);

        $identifier = $data['username'];
        $user = User::with('role')
            ->where(function ($query) use ($identifier): void {
                $query->where('username', $identifier)
                    ->orWhereRaw('LOWER(email) = ?', [strtolower($identifier)]);
            })
            ->where('is_active', true)
            ->first();
        $valid = $user && (isset($data['password'])
            ? Hash::check($data['password'], $user->password)
            : (filled($user->pin) && Hash::check($data['pin'], $user->pin)));

        abort_unless($valid, 422, 'Invalid credentials.');

        if ($user->requiresEmailVerification() && ! $user->hasVerifiedEmail()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Verify your email address before signing in.',
                'code' => 'email_verification_required',
                'email' => $user->email,
            ], 403));
        }

        $user->update(['last_login_at' => now()]);

        return [
            'token' => $user->createToken($data['device_name'] ?? 'TablePlay Staff')->plainTextToken,
            'user' => $user,
        ];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return $request->user()->load('role');
    }
}
