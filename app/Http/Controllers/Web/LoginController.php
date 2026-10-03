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
            'username' => ['required', 'string', 'max:100'],
            'credential' => ['required', 'string', 'max:255'],
        ]);
        $user = User::with('role')
            ->where('username', $data['username'])
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

        return redirect()->intended('/'.$user->role->name);
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
