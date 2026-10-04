<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CloudRestaurant;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

class TrialRegistrationController extends Controller
{
    public function create(): View
    {
        $this->ensureCloud();

        return view('auth.trial-register');
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $this->ensureCloud();

        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'mobile' => preg_replace('/[^+0-9]/', '', (string) $request->input('mobile')),
        ]);

        $data = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'mobile' => ['required', 'regex:/^\+?[1-9][0-9]{7,14}$/', Rule::unique('users', 'mobile')],
            'city' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', 'max:255', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
            'terms' => ['accepted'],
            'privacy' => ['accepted'],
            'website' => ['prohibited'],
        ], [
            'mobile.regex' => 'Enter a valid mobile number with country code, for example +919876543210.',
            'terms.accepted' => 'Accept the Terms of Service to start a trial.',
            'privacy.accepted' => 'Accept the Privacy Policy to start a trial.',
        ]);

        [$user, $restaurant] = DB::transaction(function () use ($data): array {
            $role = Role::query()->where('name', 'restaurant_owner')->firstOrFail();
            $user = User::create([
                'role_id' => $role->id,
                'name' => trim($data['owner_name']),
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                'username' => 'owner-'.Str::lower(Str::random(20)),
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $restaurant = CloudRestaurant::create([
                'owner_user_id' => $user->id,
                'restaurant_uuid' => (string) Str::uuid(),
                'name' => trim($data['restaurant_name']),
                'owner_name' => trim($data['owner_name']),
                'owner_email' => $data['email'],
                'owner_phone' => $data['mobile'],
                'city' => trim($data['city']),
                'status' => 'active',
                'trial_registered_at' => now(),
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
            ]);

            return [$user, $restaurant];
        });

        Auth::login($user, true);
        $request->session()->regenerate();
        $audit->record($request, 'cloud_trial.registered', $restaurant, null, [
            'restaurant_uuid' => $restaurant->restaurant_uuid,
            'owner_user_id' => $user->id,
            'city' => $restaurant->city,
        ]);

        try {
            $user->sendEmailVerificationNotification();
            $message = 'Your restaurant is registered. Check your inbox to activate the 14-day trial.';
        } catch (Throwable $exception) {
            report($exception);
            $message = 'Your restaurant is registered, but the verification email could not be sent. Use the resend button after checking the mail configuration.';
        }

        return redirect()->route('verification.notice')->with('status', $message);
    }

    private function ensureCloud(): void
    {
        abort_unless(
            config('tableplay.mode') === 'cloud'
            || config('tableplay.cloud_console_enabled')
            || app()->environment('testing'),
            404,
        );
    }
}
