<?php

namespace App\Services;

use App\Models\{Device, User};
use Illuminate\Auth\Access\AuthorizationException;

class BroadcastChannelAccessService
{
    public function __construct(private EntitlementService $entitlements) {}

    public function allows(User|Device $actor, string $channel): bool
    {
        $channel = preg_replace('/^(private|presence)-/', '', trim($channel)) ?? '';

        if ($actor instanceof User) {
            if (! $actor->is_active) return false;
            if ($channel === 'counter') return $actor->hasRole('admin', 'counter');
            if ($channel === 'kitchen') return $actor->hasRole('admin', 'kitchen');
            if (preg_match('/^staff\.(\d+)$/D', $channel, $matches)) {
                return $actor->id === (int) $matches[1] || $actor->hasRole('admin');
            }

            return preg_match('/^table\.\d+$/D', $channel) === 1;
        }

        if (! $actor->is_active || ! preg_match('/^table\.(\d+)$/D', $channel, $matches)) {
            return false;
        }

        $pairing = $actor->pairings()
            ->where('dining_table_id', (int) $matches[1])
            ->where('is_active', true)
            ->whereHas('diningTable', fn ($query) => $query
                ->where('is_active', true)
                ->where('status', '!=', 'disabled'))
            ->first();

        if (! $pairing) return false;

        try {
            $this->entitlements->assertCustomerAppAccess($pairing);
            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
