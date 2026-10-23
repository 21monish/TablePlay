<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountPasswordReset;
use App\Services\AuditService;
use App\Services\OutboundMailSafety;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class PasswordRecoveryController extends Controller
{
    private const SENT = 'If an active account uses this email and email delivery is available, you will receive a reset link. Check your inbox and spam folder. If it does not arrive, contact your administrator.';

    public function create()
    {
        return $this->page('auth.forgot-password');
    }

    public function edit(Request $request, string $token)
    {
        return $this->page('auth.reset-password', [
            'token' => $token,
            'email' => is_string($request->query('email')) ? $request->query('email') : '',
        ]);
    }

    public function send(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $base = parse_url((string) config('app.url'));
        // Do not send recovery secrets to a log/array transport or an invalid URL.
        if (! OutboundMailSafety::canDeliver()
            || ! is_array($base) || ! in_array($base['scheme'] ?? '', ['http', 'https'], true)
            || empty($base['host']) || isset($base['user']) || isset($base['query']) || isset($base['fragment'])) {
            return back()->withErrors(['email' => 'Password recovery email is unavailable. Contact your administrator.'])->onlyInput('email');
        }
        try {
            $user = User::whereRaw('LOWER(email) = ?', [$data['email']])->where('is_active', true)->first();
            // Use the broker for unknown/disabled addresses too: its timebox
            // prevents a fast "unknown account" response disclosing membership.
            Password::sendResetLink(['email' => $user?->email ?? $data['email'], 'is_active' => true], function (User $user, string $token): void {
                $user->notify(new AccountPasswordReset($token));
            });
        } catch (Throwable) {
            // Same public result for unknown addresses and delivery errors. Never
            // log addresses, tokens, request bodies or raw provider exceptions.
            Log::warning('Account recovery could not complete; check database and mail service health.');
        }

        return redirect()->route('password.request')->with('status', self::SENT);
    }

    public function update(Request $request, AuditService $audit)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['bail', 'required', 'confirmed', 'string', 'max:72', function ($attribute, $value, $fail) {
                if (strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes. Use fewer characters.');
                }
            }, PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->only('email'));
        }
        $data = $validator->validated();
        // Serialize resets for the same account so concurrent replay cannot use
        // the same token twice. Broker token deletion is in this transaction.
        $status = DB::transaction(function () use ($data, $request, $audit) {
            $user = User::whereRaw('LOWER(email) = ?', [$data['email']])->where('is_active', true)->lockForUpdate()->first();
            if (! $user) {
                return Password::INVALID_TOKEN;
            }
            $data['email'] = $user->email;
            $data['is_active'] = true;

            return Password::reset($data, function (User $user, string $password) use ($request, $audit): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                $audit->record($request, 'account.password_reset', $user);
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This reset link is invalid or expired. Request a new link.'])->withInput($request->only('email'));
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Password updated. Sign in with your new password.');
    }

    private function page(string $view, array $data = [])
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
