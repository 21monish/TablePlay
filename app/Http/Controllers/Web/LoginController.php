<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function create()
    {
        return view('auth.signin');
    }

    public function store(Request $request)
    {
        $request->merge(['username' => trim((string) $request->input('username'))]);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'credential' => ['required', 'string', 'max:255'],
        ]);
        $identifier = $data['username'];
        $user = User::with('role')
            ->where(function ($query) use ($identifier): void {
                $query->where('username', $identifier)
                    ->orWhereRaw('LOWER(email) = ?', [strtolower($identifier)]);
            })
            ->where('is_active', true)
            ->first();
        $valid = $user && (Hash::check($data['credential'], $user->password)
            || (filled($user->pin) && Hash::check($data['credential'], $user->pin)));

        if (! $valid) {
            return back()->withErrors(['username' => 'Invalid username, password, or PIN.'])->onlyInput('username');
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);

        if ($user->requiresEmailVerification() && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended($this->workspaceFor($user));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    private function workspaceFor(User $user): string
    {
        return $user->hasRole('restaurant_owner') ? '/account' : '/'.$user->role->name;
    }
}
