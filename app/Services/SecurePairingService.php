<?php

namespace App\Services;

use App\Models\{Device, DiningTable, PairingToken};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SecurePairingService
{
    public function issue(DiningTable $table, int $userId, array|string $connection): array
    {
        app(EntitlementService::class)->assertCanPair();

        if (is_string($connection)) {
            $apiBaseUrl = rtrim($connection, '/');
            $uri = parse_url($apiBaseUrl);
            $host = $uri['host'] ?? '127.0.0.1';
            $connection = [
                'server_id' => null,
                'server_name' => 'TablePlay Restaurant',
                'api_base_url' => $apiBaseUrl,
                'reverb_url' => 'ws://'.$host.':8080',
            ];
        }

        if (! $table->is_active || $table->status === 'disabled') {
            throw ValidationException::withMessages(['table' => 'Enable this table before creating a pairing QR.']);
        }
        if ($table->pairings()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['table' => 'This table already has an active tablet. Unpair it first.']);
        }
        PairingToken::where('dining_table_id', $table->id)->whereNull('used_at')->update(['expires_at' => now()]);
        $plain = Str::random(64);
        $record = PairingToken::create([
            'token_hash' => hash('sha256', $plain),
            'dining_table_id' => $table->id,
            'created_by' => $userId,
            'expires_at' => now()->addMinutes(15),
        ]);
        return [
            'type' => 'tableplay_pairing', 'version' => 2,
            'server_id' => $connection['server_id'],
            'server_name' => $connection['server_name'],
            'api_base_url' => rtrim($connection['api_base_url'], '/'),
            'reverb_url' => $connection['reverb_url'],
            'pairing_token' => $plain,
            'payload_signature' => hash_hmac('sha256', $plain, (string) config('app.key')),
            'table_code' => $table->table_code,
            'table_name' => $table->table_name,
            'expires_at' => $record->expires_at->toIso8601String(),
        ];
    }

    public function consume(array $data): array
    {
        if (! hash_equals(
            hash_hmac('sha256', $data['pairing_token'], (string) config('app.key')),
            (string) ($data['payload_signature'] ?? ''),
        )) {
            throw ValidationException::withMessages(['pairing_token' => 'This pairing QR was not issued by this TablePlay server. Generate a new QR from Admin.']);
        }

        return DB::transaction(function () use ($data) {
            $record = PairingToken::with('diningTable')->where('token_hash', hash('sha256', $data['pairing_token']))
                ->lockForUpdate()->first();
            if (! $record || $record->used_at || $record->expires_at->isPast()) {
                throw ValidationException::withMessages(['pairing_token' => 'This pairing QR is invalid, expired, or already used. Generate a new QR from Admin.']);
            }
            $device = Device::query()->where('device_uuid', $data['device_uuid'])->lockForUpdate()->first();

            // A UUID identifies one physical installation, but it is not a secret.
            // Never let a pairing QR rotate credentials for a tablet that is already
            // paired. An administrator must explicitly unpair that device first.
            if ($device?->pairings()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'device_uuid' => 'This device identity is already paired. Unpair or reset that tablet from Admin before pairing it again.',
                ]);
            }

            if ($device && $device->device_type !== 'tablet') {
                throw ValidationException::withMessages([
                    'device_uuid' => 'This device identity belongs to a staff application and cannot be used by the Customer app.',
                ]);
            }

            $attributes = [
                'device_name' => $data['device_name'], 'device_type' => 'tablet',
                'app_version' => $data['app_version'] ?? null, 'last_seen_at' => now(),
                'ip_address' => request()->ip(), 'is_active' => true,
            ];
            if ($device) $device->update($attributes);
            else $device = Device::create(['device_uuid' => $data['device_uuid']] + $attributes);
            $device->tokens()->delete();
            $pairing = app(DevicePairingService::class)->pair($device, $record->diningTable, $record->created_by);
            $record->update(['used_at' => now(), 'used_by_device_id' => $device->id]);
            return [
                'device' => $device, 'pairing' => $pairing->load('diningTable'),
                'table_code' => $record->diningTable->table_code,
                'token' => $device->createToken('tablet')->plainTextToken,
            ];
        });
    }
}
