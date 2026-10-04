<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Notifications\TrialWelcomeNotification;
use App\Services\TrialProvisioningService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        $user = $request->user()->loadMissing('role');

        if (! $user->requiresEmailVerification() || $user->hasVerifiedEmail()) {
            return redirect($this->workspaceFor($user));
        }

        return view('auth.verify-email', ['user' => $user]);
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user()->loadMissing('role');

        if (! $user->requiresEmailVerification() || $user->hasVerifiedEmail()) {
            return redirect($this->workspaceFor($user));
        }

        if (! filled($user->email)) {
            return back()->withErrors(['email' => 'This account has no email address. Ask an administrator to add one.']);
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['email' => 'The verification email could not be sent. Check the mail server configuration and try again.']);
        }

        return back()->with('status', 'A new verification link has been sent to '.$user->email.'.');
    }

    public function verify(EmailVerificationRequest $request, TrialProvisioningService $trials): RedirectResponse
    {
        $user = $request->user()->loadMissing('role');

        abort_unless($user->requiresEmailVerification(), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $welcomeFailed = false;
        if ($user->hasRole('restaurant_owner')) {
            $user = $user->fresh('role', 'cloudRestaurant');
            $subscription = $trials->provision($user);

            if (! $user->cloudRestaurant->welcome_email_sent_at) {
                try {
                    $user->notify(new TrialWelcomeNotification($subscription));
                    $user->cloudRestaurant->update(['welcome_email_sent_at' => now()]);
                } catch (Throwable $exception) {
                    report($exception);
                    $welcomeFailed = true;
                }
            }
        }

        $response = redirect($this->workspaceFor($user))->with('status', 'Email address verified successfully.');

        return $welcomeFailed
            ? $response->with('warning', 'Your trial is active, but the welcome email could not be sent. You can continue setup from this account.')
            : $response;
    }

    private function workspaceFor($user): string
    {
        return $user->hasRole('restaurant_owner') ? '/account' : '/'.($user->role?->name ?? 'login');
    }
}
