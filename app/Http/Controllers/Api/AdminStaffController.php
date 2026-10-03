<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{AuditLog, Category, Device, DevicePairing, DiningTable, Game, MenuItem, Order, Payment, RestaurantSetting, Role, ServiceRequest, TableSession, User};
use App\Services\{AuditService, DevicePairingService, EntitlementService, PublicAssetStorage, SystemHealthService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminStaffController extends Controller
{
    private function stats(): array
    {
        return [
            'sales_today' => Payment::whereDate('paid_at', now()->toDateString())->sum('amount'),
            'orders_today' => Order::whereDate('created_at', now()->toDateString())->whereNotIn('status', ['cancelled', 'rejected'])->count(),
            'open_tables' => TableSession::whereIn('status', ['open', 'billing'])->count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
        ];
    }

    public function dashboard(): array
    {
        return [
            'entitlements' => app(EntitlementService::class)->state(),
            'stats' => $this->stats(),
            'tables' => DiningTable::with(['sessions' => fn ($query) => $query->whereIn('status', ['open', 'billing'])])->orderBy('table_code')->get(),
            'recent_orders' => Order::with('items', 'tableSession.diningTable')->latest()->limit(7)->get(),
            'requests' => ServiceRequest::with('tableSession.diningTable')->whereIn('status', ['pending', 'acknowledged'])->oldest()->limit(6)->get(),
            'devices' => Device::latest('last_seen_at')->limit(5)->get(),
        ];
    }

    public function tables(): array
    {
        return [
            'entitlements' => app(EntitlementService::class)->state(),
            'tables' => DiningTable::with(['pairings' => fn ($query) => $query->where('is_active', true)->with('device')])
                ->withCount(['sessions', 'sessions as active_sessions_count' => fn ($query) => $query->whereIn('status', ['open', 'billing'])])
                ->orderBy('table_code')->get(),
            'devices' => Device::with(['pairings' => fn ($query) => $query->where('is_active', true)->with('diningTable')])->latest()->get(),
        ];
    }

    public function team(): array
    {
        $staffRoles = ['admin', 'counter', 'kitchen', 'waiter'];

        return [
            'entitlements' => app(EntitlementService::class)->state(),
            'roles' => Role::whereIn('name', $staffRoles)->orderBy('name')->get(),
            'staff' => User::with('role')->whereHas('role', fn ($query) => $query->whereIn('name', $staffRoles))->orderBy('name')->get(),
        ];
    }

    public function catalog(): array
    {
        return [
            'entitlements' => app(EntitlementService::class)->state(),
            'categories' => Category::with(['menuItems' => fn ($query) => $query->orderBy('name')])->orderBy('sort_order')->get(),
            'games' => Game::orderBy('sort_order')->get(),
        ];
    }

    public function reports(): array
    {
        $orders = Order::whereNotIn('status', ['cancelled', 'rejected']);
        return [
            'entitlements' => app(EntitlementService::class)->state(),
            'sales' => (clone $orders)->selectRaw('date(created_at) sale_date, count(*) orders_count, sum(total_amount) total')->groupBy('sale_date')->latest('sale_date')->limit(30)->get(),
            'audits' => AuditLog::with('user')->latest()->limit(100)->get(),
            'summary' => [
                'revenue' => Payment::sum('amount'),
                'payments' => Payment::count(),
                'orders' => (clone $orders)->count(),
                'average' => (clone $orders)->avg('total_amount') ?? 0,
            ],
        ];
    }

    public function settings(): array
    {
        return ['entitlements' => app(EntitlementService::class)->state(), 'settings' => RestaurantSetting::first()];
    }

    public function system(SystemHealthService $health): array
    {
        return ['entitlements' => app(EntitlementService::class)->state(), 'health' => $health->snapshot()];
    }

    public function overview(SystemHealthService $health): array
    {
        return [
            'entitlements' => app(EntitlementService::class)->state(),
            'stats' => $this->stats(),
            'tables' => DiningTable::with(['pairings' => fn ($query) => $query->where('is_active', true)->with('device')])
                ->withCount(['sessions', 'sessions as active_sessions_count' => fn ($query) => $query->whereIn('status', ['open', 'billing'])])
                ->orderBy('table_code')->get(),
            'staff' => User::with('role')->whereHas('role', fn ($query) => $query->whereIn('name', ['admin', 'counter', 'kitchen', 'waiter']))->orderBy('name')->get(),
            'menu_items' => MenuItem::with('category')->orderBy('name')->get(),
            'games' => Game::orderBy('sort_order')->get(),
            'devices' => Device::with(['pairings' => fn ($query) => $query->where('is_active', true)->with('diningTable')])->latest()->get(),
            'settings' => RestaurantSetting::first(),
            'health' => $health->snapshot(),
        ];
    }

    public function storeTable(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'table_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', 'unique:dining_tables'],
            'table_name' => ['required', 'string', 'max:100'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);
        $table = DiningTable::create($data + ['status' => 'available', 'is_active' => true]);
        $audit->record($request, 'table.created.mobile', $table, null, $table->toArray());
        return response()->json($table, 201);
    }

    public function updateTable(Request $request, DiningTable $table, AuditService $audit): DiningTable
    {
        $data = $request->validate([
            'table_name' => ['required', 'string', 'max:100'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'status' => ['required', 'in:available,occupied,reserved,cleaning,disabled'],
            'is_active' => ['required', 'boolean'],
        ]);
        $disabling = !$data['is_active'] || $data['status'] === 'disabled';
        if ($disabling && $table->sessions()->whereIn('status', ['open', 'billing'])->exists()) {
            throw ValidationException::withMessages(['table' => 'Close the active table session before disabling this table.']);
        }

        DB::transaction(function () use ($request, $table, $audit, $data, $disabling) {
            $old = $table->toArray();
            $table->update(array_merge($data, ['status' => $disabling ? 'disabled' : $data['status'], 'is_active' => !$disabling]));
            if ($disabling) {
                $table->pairings()->where('is_active', true)->update(['is_active' => false, 'unpaired_at' => now(), 'updated_at' => now()]);
            }
            $audit->record($request, 'table.updated.mobile', $table, $old, $table->fresh()->toArray());
        });

        return $table->fresh();
    }

    public function destroyTable(Request $request, DiningTable $table, AuditService $audit): array
    {
        if ($table->sessions()->whereIn('status', ['open', 'billing'])->exists()) {
            throw ValidationException::withMessages(['table' => 'This table is serving guests. Close its active session before removing it.']);
        }
        $hasHistory = $table->sessions()->exists();
        DB::transaction(function () use ($request, $table, $audit, $hasHistory) {
            $old = $table->toArray();
            $table->pairings()->where('is_active', true)->update(['is_active' => false, 'unpaired_at' => now(), 'updated_at' => now()]);
            if ($hasHistory) {
                $table->update(['status' => 'disabled', 'is_active' => false]);
                $audit->record($request, 'table.archived.mobile', $table, $old, $table->fresh()->toArray());
            } else {
                $audit->record($request, 'table.deleted.mobile', $table, $old, null);
                $table->delete();
            }
        });
        return ['mode' => $hasHistory ? 'archived' : 'deleted', 'message' => $hasHistory ? 'Table archived because it has service history.' : 'Unused table deleted.'];
    }

    public function pairDevice(Request $request, DevicePairingService $service, AuditService $audit)
    {
        $data = $request->validate(['device_id' => ['required', 'exists:devices,id'], 'dining_table_id' => ['required', 'exists:dining_tables,id']]);
        $pairing = $service->pair(Device::findOrFail($data['device_id']), DiningTable::findOrFail($data['dining_table_id']), $request->user()->id);
        $audit->record($request, 'device.paired.mobile', $pairing, null, $pairing->toArray());
        return response()->json($pairing->load('device', 'diningTable'), 201);
    }

    public function unpairDevice(Request $request, DevicePairing $pairing, AuditService $audit): DevicePairing
    {
        abort_unless($pairing->is_active, 422, 'This tablet is already unpaired.');
        $old = $pairing->toArray();
        $pairing->update(['is_active' => false, 'unpaired_at' => now()]);
        $audit->record($request, 'device.unpaired.mobile', $pairing, $old, $pairing->fresh()->toArray());
        return $pairing->fresh('device', 'diningTable');
    }

    public function storeUser(Request $request, AuditService $audit)
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'username' => trim((string) $request->input('username')),
            'email' => $request->filled('email') ? strtolower(trim((string) $request->input('email'))) : null,
            'mobile' => $request->filled('mobile') ? trim((string) $request->input('mobile')) : null,
            'pin' => $request->filled('pin') ? trim((string) $request->input('pin')) : null,
        ]);
        $selectedRole = Role::query()->find($request->input('role_id'));
        $emailRequired = (bool) config('tableplay.require_privileged_email_verification')
            && $selectedRole?->name === 'admin';
        $data = $request->validate([
            'role_id' => ['required', Rule::exists('roles', 'id')->where(fn ($query) => $query->whereIn('name', ['admin', 'counter', 'kitchen', 'waiter']))],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users'],
            'email' => [Rule::requiredIf($emailRequired), 'nullable', 'email:rfc', 'max:255', 'unique:users'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'pin' => ['nullable', 'digits_between:4,12'],
        ]);
        $user = User::create($data + ['is_active' => true]);
        $audit->record($request, 'user.created.mobile', $user, null, $user->only(['role_id', 'name', 'username', 'email', 'mobile', 'is_active']));
        $user->load('role');
        $verificationEmailSent = false;
        $warning = null;
        if ($user->requiresEmailVerification()) {
            try {
                $user->sendEmailVerificationNotification();
                $verificationEmailSent = true;
            } catch (Throwable $exception) {
                report($exception);
                $warning = 'Account created, but the verification email could not be sent. Check the mail settings and resend it.';
            }
        }

        return response()->json(array_merge($user->toArray(), [
            'verification_required' => $user->requiresEmailVerification(),
            'verification_email_sent' => $verificationEmailSent,
            'warning' => $warning,
        ]), 201);
    }

    public function storeCategory(Request $request, AuditService $audit)
    {
        $category = Category::create($request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:1000'], 'sort_order' => ['required', 'integer', 'min:0', 'max:10000']]) + ['is_active' => true]);
        $audit->record($request, 'category.created.mobile', $category, null, $category->toArray());
        return response()->json($category, 201);
    }

    public function storeMenuItem(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'], 'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'], 'short_description' => ['nullable', 'string', 'max:180'],
            'ingredients' => ['nullable', 'string', 'max:2000'], 'allergens' => ['nullable', 'array', 'max:20'], 'allergens.*' => ['string', 'max:80', 'distinct'],
            'spice_level' => ['nullable', 'in:none,mild,medium,hot'], 'calories' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'], 'discount_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'lte:price'],
            'food_type' => ['required', 'in:veg,non_veg,egg,other'], 'preparation_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'customizations' => ['nullable', 'array', 'max:20'], 'is_recommended' => ['nullable', 'boolean'], 'is_bestseller' => ['nullable', 'boolean'],
            'image_path' => ['nullable', 'string', 'max:500', 'regex:/^(\/images\/|\/storage\/menu-items\/|https?:\/\/)/'],
        ]);
        $item = MenuItem::create($data + ['spice_level' => 'none', 'is_available' => true, 'is_active' => true]);
        $audit->record($request, 'menu_item.created.mobile', $item, null, $item->toArray());
        return response()->json($item->load('category'), 201);
    }

    public function updateMenuItem(Request $request, MenuItem $menuItem, AuditService $audit): MenuItem
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'], 'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'], 'short_description' => ['nullable', 'string', 'max:180'],
            'ingredients' => ['nullable', 'string', 'max:2000'], 'allergens' => ['nullable', 'array', 'max:20'], 'allergens.*' => ['string', 'max:80', 'distinct'],
            'spice_level' => ['required', 'in:none,mild,medium,hot'], 'calories' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'], 'discount_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'lte:price'],
            'food_type' => ['required', 'in:veg,non_veg,egg,other'], 'preparation_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'customizations' => ['nullable', 'array', 'max:20'], 'is_recommended' => ['nullable', 'boolean'], 'is_bestseller' => ['nullable', 'boolean'],
            'image_path' => ['nullable', 'string', 'max:500', 'regex:/^(\/images\/|\/storage\/menu-items\/|https?:\/\/)/'],
        ]);
        $old = $menuItem->toArray();
        $menuItem->update($data);
        $audit->record($request, 'menu_item.updated.mobile', $menuItem, $old, $menuItem->fresh()->toArray());
        return $menuItem->fresh('category');
    }

    public function uploadMenuItemImage(Request $request, MenuItem $menuItem, AuditService $audit, PublicAssetStorage $assets): MenuItem
    {
        $request->validate(['image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120']]);
        $old = $menuItem->toArray();
        $oldPath = $menuItem->image_path;
        $path = $assets->store($request->file('image'), 'menu-items');
        $menuItem->update(['image_path' => $path]);

        if ($oldPath && $oldPath !== $path) {
            $assets->delete($oldPath);
        }

        $audit->record($request, 'menu_item.image_updated.mobile', $menuItem, $old, $menuItem->fresh()->toArray());
        return $menuItem->fresh('category');
    }

    public function updateSettings(Request $request, AuditService $audit): RestaurantSetting
    {
        $data = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:255'], 'tagline' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:1000'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:30'], 'brand_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'timezone' => ['required', 'timezone'], 'currency' => ['required', 'alpha', 'size:3'], 'tax_name' => ['required', 'string', 'max:50'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'], 'game_duration_minutes' => ['required', 'integer', 'min:1', 'max:240'],
            'kitchen_refresh_seconds' => ['required', 'integer', 'min:2', 'max:30'], 'device_offline_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'receipt_footer' => ['nullable', 'string', 'max:1000'],
        ]);
        $settings = RestaurantSetting::firstOrCreate(['id' => 1], $data);
        $old = $settings->toArray();
        $settings->update($data);
        $audit->record($request, 'settings.updated.mobile', $settings, $old, $settings->fresh()->toArray());
        return $settings->fresh();
    }

    public function updateBranding(Request $request, AuditService $audit, PublicAssetStorage $assets): RestaurantSetting
    {
        $request->validate([
            'restaurant_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'app_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);
        $settings = RestaurantSetting::firstOrCreate(['id' => 1]);
        $old = $settings->toArray();
        $paths = [];
        if ($request->hasFile('restaurant_logo')) $paths['restaurant_logo_path'] = $assets->store($request->file('restaurant_logo'), 'branding');
        if ($request->hasFile('app_logo')) {
            $path = $assets->store($request->file('app_logo'), 'branding');
            foreach (['customer_app_logo_path', 'staff_app_logo_path', 'system_logo_path', 'favicon_path'] as $field) $paths[$field] = $path;
        }
        abort_if($paths === [], 422, 'Select at least one branding file.');
        $settings->update($paths);
        $audit->record($request, 'branding.updated.mobile', $settings, $old, $settings->fresh()->toArray());
        return $settings->fresh();
    }

    public function storeGame(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'slug' => ['required', 'alpha_dash', 'unique:games'],
            'description' => ['nullable', 'string', 'max:1000'], 'player_mode' => ['required', 'in:one,two,four'],
            'game_path' => ['required', 'string', 'max:255', 'regex:/^\/games\/[A-Za-z0-9._\/-]+$/'], 'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);
        $game = Game::create($data + ['is_active' => true]);
        $audit->record($request, 'game.created.mobile', $game, null, $game->toArray());
        return response()->json($game, 201);
    }

    public function toggleUser(Request $request, User $user, AuditService $audit): User
    {
        abort_unless($user->loadMissing('role')->hasRole('admin', 'counter', 'kitchen', 'waiter'), 403, 'Platform accounts cannot be managed from a restaurant workspace.');
        abort_if($request->user()->is($user), 422, 'You cannot disable your own account.');
        $old = $user->only('is_active');
        $user->update(['is_active' => !$user->is_active]);
        $audit->record($request, 'user.toggled.mobile', $user, $old, $user->only('is_active'));
        return $user->fresh('role');
    }

    public function toggleMenuItem(Request $request, MenuItem $menuItem, AuditService $audit): MenuItem
    {
        $old = $menuItem->only('is_available');
        $menuItem->update(['is_available' => !$menuItem->is_available]);
        $audit->record($request, 'menu_item.availability.mobile', $menuItem, $old, $menuItem->only('is_available'));
        return $menuItem->fresh('category');
    }

    public function toggleGame(Request $request, Game $game, AuditService $audit): Game
    {
        $old = $game->only('is_active');
        $game->update(['is_active' => !$game->is_active]);
        $audit->record($request, 'game.toggled.mobile', $game, $old, $game->only('is_active'));
        return $game->fresh();
    }
}
