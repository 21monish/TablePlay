<?php

namespace Tests\Feature;

use App\Models\{CommercialPlan, Device, DiningTable, Role, User};
use App\Services\{DevicePairingService, EntitlementService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BroadcastAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_staff_cannot_authorize_private_channels(): void
    {
        $counterRole = Role::create(['name' => 'counter', 'display_name' => 'Counter']);
        $counter = User::create([
            'role_id' => $counterRole->id, 'name' => 'Counter', 'username' => 'channel-counter',
            'password' => 'secret-password', 'is_active' => true,
        ]);
        $token = $counter->createToken('broadcast-test')->plainTextToken;

        $this->authorize($token, 'private-counter')->assertOk();

        $counter->update(['is_active' => false]);
        auth()->forgetGuards();
        $this->authorize($token, 'private-counter')->assertForbidden();
        auth()->forgetGuards();
        $this->authorize($token, 'private-staff.'.$counter->id)->assertForbidden();
    }

    public function test_table_channel_rechecks_customer_feature_and_effective_table_slot(): void
    {
        $this->activate('premium');
        $connections = collect();

        foreach (range(1, 6) as $number) {
            $table = DiningTable::create([
                'table_code' => sprintf('T%02d', $number),
                'table_name' => 'Table '.$number,
                'capacity' => 4,
                'status' => 'available',
                'is_active' => true,
            ]);
            $device = Device::create([
                'device_uuid' => (string) Str::uuid(),
                'device_name' => 'Tablet '.$number,
                'device_type' => 'tablet',
                'is_active' => true,
            ]);
            app(DevicePairingService::class)->pair($device, $table);
            $connections->push([
                'table' => $table,
                'device' => $device,
                'token' => $device->createToken('broadcast-test')->plainTextToken,
            ]);
        }

        $this->activate('best');

        $oldest = $connections->first();
        $overflow = $connections->last();
        auth()->forgetGuards();
        $this->authorize($oldest['token'], 'private-table.'.$oldest['table']->id)->assertOk();
        auth()->forgetGuards();
        $this->authorize($overflow['token'], 'private-table.'.$overflow['table']->id)->assertForbidden();
        auth()->forgetGuards();
        $this->authorize($oldest['token'], 'private-table.'.$overflow['table']->id)->assertForbidden();

        $this->activate('simple');
        auth()->forgetGuards();
        $this->authorize($oldest['token'], 'private-table.'.$oldest['table']->id)->assertForbidden();

        $this->activate('premium');
        auth()->forgetGuards();
        $this->authorize($overflow['token'], 'private-table.'.$overflow['table']->id)->assertOk();

        $overflow['device']->update(['is_active' => false]);
        auth()->forgetGuards();
        $this->authorize($overflow['token'], 'private-table.'.$overflow['table']->id)->assertForbidden();
    }

    private function authorize(string $token, string $channel)
    {
        return $this->withToken($token)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }

    private function activate(string $slug): void
    {
        app(EntitlementService::class)->activate(CommercialPlan::where('slug', $slug)->firstOrFail());
    }
}
