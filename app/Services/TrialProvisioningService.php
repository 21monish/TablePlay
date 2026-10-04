<?php

namespace App\Services;

use App\Models\CloudRestaurant;
use App\Models\CloudSubscription;
use App\Models\CloudSubscriptionEvent;
use App\Models\CommercialPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TrialProvisioningService
{
    public function provision(User $user): CloudSubscription
    {
        $user->loadMissing('role', 'cloudRestaurant');

        if (! $user->hasRole('restaurant_owner') || ! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => 'Verify the restaurant owner email before activating a free trial.',
            ]);
        }

        if (! $user->cloudRestaurant) {
            throw ValidationException::withMessages([
                'restaurant' => 'The restaurant account is incomplete. Contact TablePlay support.',
            ]);
        }

        return DB::transaction(function () use ($user): CloudSubscription {
            $restaurant = CloudRestaurant::query()->lockForUpdate()->findOrFail($user->cloudRestaurant->id);
            $existing = $restaurant->subscriptions()->latest('id')->first();

            if ($existing) {
                return $existing;
            }

            $plan = CommercialPlan::query()
                ->where('slug', 'trial')
                ->where('is_active', true)
                ->first();

            if (! $plan) {
                throw ValidationException::withMessages([
                    'trial' => 'The free trial is temporarily unavailable. Contact TablePlay support.',
                ]);
            }

            $startsAt = now();
            $expiresAt = $startsAt->copy()->addDays(max(1, (int) $plan->trial_days));
            $subscription = CloudSubscription::create([
                'cloud_restaurant_id' => $restaurant->id,
                'commercial_plan_id' => $plan->id,
                'license_reference' => 'TP-'.Str::upper(Str::random(14)),
                'status' => 'trial',
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'grace_ends_at' => $expiresAt->copy()->addDays(max(0, (int) $plan->grace_days)),
                'max_installations' => 1,
            ]);

            CloudSubscriptionEvent::create([
                'cloud_subscription_id' => $subscription->id,
                'event' => 'self_service_trial_activated',
                'to_status' => 'trial',
                'to_plan_id' => $plan->id,
                'effective_at' => $startsAt,
                'reason' => 'Owner email verified',
                'created_by' => $user->id,
            ]);

            return $subscription->load('plan', 'restaurant');
        });
    }
}
