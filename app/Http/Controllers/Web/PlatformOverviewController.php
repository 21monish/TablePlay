<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{CloudInstallation, CloudRestaurant, CloudRestaurantFinancialReport, Role, User};
use App\Services\PlatformFinancialSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformOverviewController extends Controller
{
    public function index(Request $request, PlatformFinancialSummary $finances)
    {
        $month = $this->month($request);
        $reports = CloudRestaurantFinancialReport::query()->where('period_month', $month->toDateString());

        return view('superadmin.overview', [
            'month' => $month,
            'counts' => [
                'users' => User::count(),
                'restaurants' => CloudRestaurant::count(),
                'active_restaurants' => CloudRestaurant::where('status', 'active')->count(),
                'installations' => CloudInstallation::where('status', 'active')->count(),
            ],
            'cash' => $finances->cash($month),
            'outstanding' => $finances->outstanding(),
            'profitTotals' => (clone $reports)->select('currency')->selectRaw('COUNT(*) as reports, SUM(net_sales) as sales, SUM(expenses) as costs, SUM(net_sales - expenses) as profit')->groupBy('currency')->orderBy('currency')->get(),
            'reportedCount' => (clone $reports)->count(),
            'recentReports' => (clone $reports)->with('restaurant:id,name')->latest('updated_at')->limit(10)->get(),
        ]);
    }

    public function users(Request $request)
    {
        $this->ensureCloud();
        $filters = $request->validate([
            'q' => 'nullable|string|max:100', 'role' => ['nullable', 'string', Rule::exists('roles', 'name')],
            'status' => ['nullable', Rule::in(['active', 'disabled', 'deleted'])],
            'verified' => ['nullable', Rule::in(['yes', 'no'])],
        ]);
        $users = User::withTrashed()->select(['id', 'role_id', 'name', 'username', 'email', 'mobile', 'is_active', 'email_verified_at', 'last_login_at', 'deleted_at'])
            ->with(['role:id,name,display_name', 'cloudRestaurant:id,owner_user_id,name'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $this->search($q, $term, ['name', 'username', 'email']))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->whereHas('role', fn ($r) => $r->where('name', $role)))
            ->when(($filters['status'] ?? null) === 'deleted', fn ($q) => $q->onlyTrashed())
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->whereNull('deleted_at')->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'disabled', fn ($q) => $q->whereNull('deleted_at')->where('is_active', false))
            ->when(($filters['verified'] ?? null) === 'yes', fn ($q) => $q->whereNotNull('email_verified_at'))
            ->when(($filters['verified'] ?? null) === 'no', fn ($q) => $q->whereNull('email_verified_at'))
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();

        return view('superadmin.users', ['users' => $users, 'roles' => Role::orderBy('display_name')->get(['id', 'name', 'display_name'])]);
    }

    public function restaurants(Request $request, PlatformFinancialSummary $finances)
    {
        $month = $this->month($request);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(['active', 'suspended', 'closed'])]]);
        $restaurants = CloudRestaurant::query()
            ->with(['ownerUser:id,name,email,email_verified_at', 'currentSubscription.plan:id,name',
                'financialReports' => fn ($q) => $q->where('period_month', $month->toDateString())])
            ->when($filters['q'] ?? null, fn ($q, $term) => $this->search($q, $term, ['name', 'owner_name', 'owner_email']))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();

        return view('superadmin.restaurants', [
            'restaurants' => $restaurants, 'month' => $month,
            'cashByRestaurant' => $finances->cash($month, $restaurants->getCollection()->modelKeys())->groupBy('cloud_restaurant_id'),
        ]);
    }

    private function search(Builder $query, string $term, array $columns): void
    {
        // Binding values protects SQL; escaping LIKE wildcards keeps searches literal.
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term)).'%';
        $query->where(function ($q) use ($term, $columns) {
            foreach ($columns as $column) {
                $q->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '!'", [mb_strtolower($term)]);
            }
        });
    }

    public function month(Request $request): CarbonImmutable
    {
        $this->ensureCloud();
        $data = $request->validate(['month' => 'nullable|date_format:Y-m']);
        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'] ?? now()->format('Y-m'));
        if ($month->isFuture() || $month->year < 2000) {
            throw ValidationException::withMessages(['month' => 'Choose a month from January 2000 through the current month.']);
        }
        return $month;
    }

    private function ensureCloud(): void
    {
        abort_unless(config('tableplay.mode') === 'cloud' || config('tableplay.cloud_console_enabled') || app()->environment(['local', 'testing']), 404);
    }
}
