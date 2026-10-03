<?php

namespace App\Services;

use App\Models\{Device, DevicePairing, DiningTable, RestaurantSubscription};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DevicePairingService
{
    public function pair(Device $device, DiningTable $table, ?int $userId = null): DevicePairing
    {
        return DB::transaction(function () use ($device, $table, $userId) {
            // Serialize quota checks so two simultaneous scans cannot claim the
            // final table slot on a finite plan.
            RestaurantSubscription::query()->latest('id')->lockForUpdate()->first();
            app(EntitlementService::class)->assertCanPair();

            $device = Device::lockForUpdate()->findOrFail($device->id);
            $table = DiningTable::lockForUpdate()->findOrFail($table->id);

            if ($device->device_type !== 'tablet' || ! $device->is_active) {
                throw ValidationException::withMessages([
                    'device' => 'Only an active Customer Table tablet can be paired to a restaurant table.',
                ]);
            }

            if (! $table->is_active || $table->status === 'disabled') {
                throw ValidationException::withMessages([
                    'table' => 'Enable this table before pairing a customer tablet.',
                ]);
            }

            $alreadyPaired = DevicePairing::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->where('device_id', $device->id)
                    ->orWhere('dining_table_id', $table->id))
                ->exists();

            if ($alreadyPaired) {
                throw ValidationException::withMessages([
                    'pairing' => 'This device or table already has an active pairing.',
                ]);
            }

            return DevicePairing::create([
                'device_id' => $device->id,
                'dining_table_id' => $table->id,
                'paired_by' => $userId,
                'paired_at' => now(),
                'is_active' => true,
            ]);
        }, 3);
    }
}
