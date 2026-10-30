<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{AuditLog, CloudInstallation, CloudInvoice, CloudPayment, CloudSubscription};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformOperationsController extends Controller
{
    public function invoices(Request $request)
    {
        $statuses = ['draft', 'issued', 'paid', 'overdue', 'void'];
        $filters = $this->filters($request, $statuses);
        $records = CloudInvoice::query()->select(['id', 'cloud_restaurant_id', 'invoice_number', 'status', 'currency', 'total', 'issued_on', 'due_on'])->with('restaurant:id,name')
            ->withSum(['payments as received' => fn ($q) => $q->where('status', 'completed')], 'amount')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $this->like($q, 'invoice_number', $term)
                ->orWhereHas('restaurant', fn ($r) => $this->like($r, 'name', $term))))
            ->orderByDesc('issued_on')->orderByDesc('id')->paginate(20)->withQueryString();

        return $this->page('invoices', 'Invoices', 'Search all customer invoices. Review totals, payment balances and existing invoice details without changing billing records.', $records, $statuses);
    }

    public function payments(Request $request)
    {
        $statuses = ['completed', 'refunded'];
        $filters = $this->filters($request, $statuses);
        $records = CloudPayment::query()->select(['id', 'cloud_restaurant_id', 'cloud_invoice_id', 'payment_number', 'method', 'amount', 'paid_at', 'status', 'refunded_at'])
            ->with(['restaurant:id,name', 'invoice:id,invoice_number,currency'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $this->like($q, 'payment_number', $term)
                ->orWhereHas('restaurant', fn ($r) => $this->like($r, 'name', $term))))
            ->orderByDesc('paid_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return $this->page('payments', 'Payments', 'Recorded subscription payments and refunds. These are TablePlay billing records, not restaurant food sales.', $records, $statuses);
    }

    public function renewals(Request $request)
    {
        $statuses = ['trial', 'active', 'grace', 'expired'];
        $filters = $this->filters($request, $statuses);
        $request->validate(['window' => ['nullable', 'string', Rule::in(['7', '30', '90', 'overdue', 'all'])]]);
        $window = $request->input('window') ?: '30';
        $records = CloudSubscription::query()->select(['id', 'cloud_restaurant_id', 'commercial_plan_id', 'status', 'expires_at'])->with(['restaurant:id,name,status', 'plan:id,name'])
            ->whereIn('id', CloudSubscription::query()->selectRaw('MAX(id)')->groupBy('cloud_restaurant_id'))
            ->whereIn('status', $statuses)->whereNotNull('expires_at')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(in_array($window, ['7', '30', '90'], true), fn ($q) => $q->whereBetween('expires_at', [now(), now()->addDays((int) $window)]))
            ->when($window === 'overdue', fn ($q) => $q->where('expires_at', '<', now()))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('restaurant', fn ($r) => $this->like($r, 'name', $term)))
            ->orderBy('expires_at')->orderBy('id')->paginate(20)->withQueryString();

        return $this->page('renewals', 'Renewals', 'Review expiring or overdue latest subscriptions. Opening this page never renews a plan or changes access.', $records, $statuses);
    }

    public function installations(Request $request)
    {
        $statuses = ['active', 'deactivated', 'transferred'];
        $filters = $this->filters($request, $statuses);
        $records = CloudInstallation::query()
            ->select(['id', 'cloud_subscription_id', 'installation_uuid', 'device_name', 'status', 'server_version', 'activated_at', 'last_seen_at', 'deactivated_at'])
            ->with(['subscription:id,cloud_restaurant_id,commercial_plan_id,status', 'subscription.restaurant:id,name', 'subscription.plan:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $this->like($q, 'device_name', $term)
                ->orWhereHas('subscription.restaurant', fn ($r) => $this->like($r, 'name', $term))))
            ->orderByDesc('activated_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return $this->page('installations', 'Server installations', 'Activation status, app versions and last contact. An active activation record does not prove a server is currently online.', $records, $statuses);
    }

    public function activity(Request $request)
    {
        $filters = $this->filters($request, []);
        $records = AuditLog::query()->select(['id', 'user_id', 'action', 'entity_type', 'entity_id', 'created_at'])
            ->with('user:id,name,deleted_at')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $this->like($q, 'action', $term)
                ->orWhereHas('user', fn ($u) => $this->like($u, 'name', $term))))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return $this->page('activity', 'Activity log', 'Read-only action history for this cloud database. Raw before/after payloads, credentials, licence material and request IP addresses are not displayed.', $records, []);
    }

    private function filters(Request $request, array $statuses): array
    {
        abort_unless(config('tableplay.mode') === 'cloud' || config('tableplay.cloud_console_enabled') || app()->environment(['local', 'testing']), 404);
        return $request->validate(['q' => 'nullable|string|max:100', 'status' => ['nullable', 'string', Rule::in($statuses)]]);
    }

    private function like(Builder $query, string $column, string $term): Builder
    {
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($term))).'%';
        return $query->whereRaw('LOWER('.$column.") LIKE ? ESCAPE '!'", [$term]);
    }

    private function page(string $section, string $title, string $description, $records, array $statuses)
    {
        return view('superadmin.operations', compact('section', 'title', 'description', 'records', 'statuses'));
    }
}
