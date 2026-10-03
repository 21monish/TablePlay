<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{DiningTable, RestaurantSetting};
use App\Services\{AuditService, LocalNetworkService, SecurePairingService, SetupWizardService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SetupWizardController extends Controller
{
    public function index(Request $request, SetupWizardService $wizard)
    {
        return view('admin.setup', $wizard->snapshot() + [
            'pairingPayload' => $request->session()->pull('pairing_payload'),
            'staffConnectionPayload' => $request->session()->pull('staff_connection_payload'),
        ]);
    }

    public function identity(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:255'], 'tagline' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:1000'],
            'currency' => ['required', 'string', 'size:3'], 'tax_name' => ['required', 'string', 'max:50'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'restaurant_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'app_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);
        $settings = RestaurantSetting::firstOrFail(); $old = $settings->toArray();
        if ($request->hasFile('restaurant_logo')) $data['restaurant_logo_path'] = '/storage/'.$request->file('restaurant_logo')->store('branding', 'public');
        if ($request->hasFile('app_logo')) {
            $path = '/storage/'.$request->file('app_logo')->store('branding', 'public');
            foreach (['customer_app_logo_path', 'staff_app_logo_path', 'system_logo_path', 'favicon_path'] as $field) $data[$field] = $path;
        }
        unset($data['restaurant_logo'], $data['app_logo']);
        $settings->update($data);
        $audit->record($request, 'setup.identity.updated', $settings, $old, $settings->fresh()->toArray());
        return back()->with('status', 'Restaurant identity, tax, and branding saved everywhere.');
    }

    public function tables(Request $request)
    {
        $data = $request->validate(['count' => ['required', 'integer', 'min:1', 'max:100'], 'capacity' => ['required', 'integer', 'min:1', 'max:50']]);
        DB::transaction(function () use ($data) {
            foreach (range(1, $data['count']) as $number) DiningTable::firstOrCreate(
                ['table_code' => sprintf('T%02d', $number)],
                ['table_name' => 'Table '.$number, 'capacity' => $data['capacity'], 'status' => 'available', 'is_active' => true],
            );
        });
        return back()->with('status', 'Dining tables are ready. Existing tables were preserved.');
    }

    public function pairingToken(Request $request, DiningTable $table, SecurePairingService $pairing, LocalNetworkService $network)
    {
        $payload = $pairing->issue($table, $request->user()->id, $network->connection($request));
        return redirect()->route('admin.setup.index')->with('pairing_payload', $payload)->with('status', 'Secure pairing QR created for '.$table->table_code.'.');
    }

    public function staffConnection(Request $request, LocalNetworkService $network)
    {
        return redirect()->route('admin.setup.index')
            ->with('staff_connection_payload', $network->staffPayload($request))
            ->with('status', 'Staff connection QR created. Scan it from Staff Android before signing in.');
    }

    public function complete(SetupWizardService $wizard)
    {
        abort_unless($wizard->snapshot()['ready'], 422, 'Complete the required setup steps first.');
        RestaurantSetting::firstOrFail()->update(['setup_completed_at' => now()]);
        return redirect()->route('admin.overview')->with('status', 'TablePlay setup completed. The restaurant is ready for its pilot test.');
    }
}
