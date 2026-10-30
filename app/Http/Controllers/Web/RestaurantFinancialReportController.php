<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CloudRestaurant;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestaurantFinancialReportController extends Controller
{
    public function index(Request $request, PlatformOverviewController $periods)
    {
        $month = $periods->month($request);
        $restaurant = $request->user()->cloudRestaurant;
        abort_unless($restaurant, 404, 'Complete restaurant onboarding first.');

        return view('restaurant-account.financial-reports', [
            'restaurant' => $restaurant, 'month' => $month,
            'report' => $restaurant->financialReports()->where('period_month', $month->toDateString())->first(),
            'history' => $restaurant->financialReports()->orderByDesc('period_month')->paginate(12)->withQueryString(),
        ]);
    }

    public function store(Request $request, PlatformOverviewController $periods, AuditService $audit)
    {
        $month = $periods->month($request);
        $data = $request->validate([
            'month' => 'required|date_format:Y-m',
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'net_sales' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'expenses' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'notes' => 'nullable|string|max:1000', 'revision' => 'required|integer|min:0',
            'share_totals' => 'required|accepted',
        ]);
        DB::transaction(function () use ($request, $month, $data, $audit) {
            $restaurant = CloudRestaurant::where('owner_user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($restaurant->status === 'active', 403, 'This restaurant account is not active.');
            $report = $restaurant->financialReports()->where('period_month', $month->toDateString())->first();
            if ((int) $data['revision'] !== ($report?->revision ?? 0)) {
                throw ValidationException::withMessages(['revision' => 'This report changed in another window. Reload the month before saving.']);
            }
            $old = $report?->toArray();
            $report ??= $restaurant->financialReports()->make(['period_month' => $month->toDateString()]);
            $report->fill(collect($data)->only(['currency', 'net_sales', 'expenses', 'notes'])->all());
            $report->submitted_by = $request->user()->id;
            $report->revision = ($report->revision ?? 0) + 1;
            $report->save();
            $audit->record($request, 'restaurant_financial_report.submitted', $report, $old, $report->toArray() + ['sharing_confirmed' => true]);
        });

        return redirect()->route('account.financial-reports.index', ['month' => $month->format('Y-m')], 303)
            ->with('status', 'Monthly totals shared with TablePlay Super Admin. This is an owner-reported summary, not an audited profit statement.');
    }
}
