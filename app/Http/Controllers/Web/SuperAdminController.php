<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CloudRestaurant;
use App\Models\CommercialPlan;
use App\Models\RestaurantSubscription;
use App\Services\AuditService;
use App\Services\EntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SuperAdminController extends Controller
{
    public const FEATURES = [
        'staff_apps' => 'Staff applications',
        'waiter_ordering' => 'Waiter ordering',
        'customer_app' => 'Customer table app',
        'games' => 'Games and timers',
        'advanced_reports' => 'Advanced reports',
        'automation' => 'Restaurant automation',
        'premium_support' => 'Priority support',
    ];

    public function index(EntitlementService $service): View
    {
        $plans = CommercialPlan::query()
            ->withCount(['subscriptions', 'cloudSubscriptions'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $subscriptions = RestaurantSubscription::query()
            ->with('plan')
            ->latest()
            ->limit(50)
            ->get();

        return view('superadmin.command-center', [
            'plans' => $plans,
            'activePlans' => $plans->where('is_active', true),
            'subscriptions' => $subscriptions,
            'currentSubscription' => $subscriptions->first(),
            'entitlements' => $service->state(),
            'featureCatalog' => self::FEATURES,
            'platformStats' => [
                'active_plans' => $plans->where('is_active', true)->count(),
                'activations' => RestaurantSubscription::count(),
                'cloud_restaurants' => CloudRestaurant::count(),
            ],
        ]);
    }

    public function storePlan(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validatedPlan($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['features'] = $this->normalizedFeatures($request);

        $plan = DB::transaction(function () use ($data) {
            if ($data['is_featured']) {
                CommercialPlan::query()->update(['is_featured' => false]);
            }

            return CommercialPlan::create($data);
        });

        $audit->record($request, 'commercial_plan.created', $plan, null, $plan->toArray());

        return back()->with('status', "{$plan->name} was created and is ready to use.");
    }

    public function updatePlan(Request $request, CommercialPlan $plan, AuditService $audit): RedirectResponse
    {
        $old = $plan->toArray();
        $data = $this->validatedPlan($request);
        $data['features'] = $this->normalizedFeatures($request);

        DB::transaction(function () use ($plan, $data) {
            if ($data['is_featured']) {
                CommercialPlan::query()->where('id', '!=', $plan->id)->update(['is_featured' => false]);
            }
            $plan->update($data);
        });

        $audit->record($request, 'commercial_plan.updated', $plan, $old, $plan->fresh()->toArray());

        return back()->with('status', "{$plan->name} was updated.");
    }

    public function togglePlan(Request $request, CommercialPlan $plan, AuditService $audit): RedirectResponse
    {
        $old = $plan->toArray();
        $plan->update(['is_active' => ! $plan->is_active]);
        $action = $plan->is_active ? 'restored' : 'archived';
        $audit->record($request, "commercial_plan.{$action}", $plan, $old, $plan->fresh()->toArray());

        return back()->with('status', "{$plan->name} was {$action}.");
    }

    public function destroyPlan(Request $request, CommercialPlan $plan, AuditService $audit): RedirectResponse
    {
        $plan->loadCount(['subscriptions', 'cloudSubscriptions']);
        if ($plan->usage_count > 0) {
            throw ValidationException::withMessages([
                'plan' => 'This plan has subscription history. Archive it instead to preserve licenses and reports.',
            ]);
        }

        $old = $plan->toArray();
        $audit->record($request, 'commercial_plan.deleted', $plan, $old, null);
        $name = $plan->name;
        $plan->delete();

        return back()->with('status', "{$name} was permanently deleted.");
    }

    public function activate(Request $request, EntitlementService $service, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('commercial_plans', 'id')->where('is_active', true)],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $plan = CommercialPlan::findOrFail($data['plan_id']);
        $days = $plan->slug === 'trial' ? ($data['duration_days'] ?? $plan->trial_days) : ($data['duration_days'] ?? null);
        $subscription = $service->activate($plan, $plan->slug === 'trial' ? 'trial' : 'active', $days);
        $audit->record($request, 'subscription.activated', $subscription, null, $subscription->toArray());

        return back()->with('status', "{$plan->name} plan activated.");
    }

    public function suspend(Request $request, RestaurantSubscription $subscription, EntitlementService $service, AuditService $audit): RedirectResponse
    {
        $this->ensureCurrentSubscription($subscription);
        $old = $subscription->toArray();
        $subscription = $service->setLocalStatus($subscription, 'suspended');
        $audit->record($request, 'subscription.suspended', $subscription, $old, $subscription->fresh()->toArray());

        return back()->with('status', 'Current subscription suspended.');
    }

    public function resume(Request $request, RestaurantSubscription $subscription, EntitlementService $service, AuditService $audit): RedirectResponse
    {
        $this->ensureCurrentSubscription($subscription);
        if ($subscription->status !== 'suspended') {
            throw ValidationException::withMessages(['subscription' => 'Only a suspended subscription can be resumed.']);
        }

        $old = $subscription->toArray();
        $status = $subscription->plan?->slug === 'trial' ? 'trial' : 'active';
        if ($subscription->expires_at?->isPast()) {
            $status = $subscription->grace_ends_at?->isFuture() ? 'grace' : 'expired';
        }
        $subscription = $service->setLocalStatus($subscription, $status);
        $audit->record($request, 'subscription.resumed', $subscription, $old, $subscription->fresh()->toArray());

        return back()->with('status', 'Subscription resumed successfully.');
    }

    private function validatedPlan(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_paired_tables' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'monthly_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'annual_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', Rule::in(array_keys(self::FEATURES))],
        ]);
    }

    private function normalizedFeatures(Request $request): array
    {
        $selected = collect($request->input('features', []));

        return collect(self::FEATURES)->mapWithKeys(fn ($label, $key) => [$key => $selected->contains($key)])->all();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        $number = 2;
        while (CommercialPlan::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$number}";
            $number++;
        }

        return $slug;
    }

    private function ensureCurrentSubscription(RestaurantSubscription $subscription): void
    {
        if ($subscription->id !== RestaurantSubscription::query()->latest('id')->value('id')) {
            throw ValidationException::withMessages(['subscription' => 'Only the current subscription can be changed.']);
        }
    }
}
