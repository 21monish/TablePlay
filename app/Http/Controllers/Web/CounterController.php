<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\GameSession;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Models\ServiceRequest;
use App\Models\TableSession;
use App\Services\AuditService;
use App\Services\BillingService;
use App\Services\EntitlementService;
use App\Services\GameAccessService;
use App\Services\OrderWorkflowService;
use Illuminate\Http\Request;

class CounterController extends Controller
{
    public function index()
    {
        return view('counter.workspace', [
            'orders' => Order::with('items', 'tableSession.diningTable')
                ->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready'])
                ->oldest()
                ->get(),
            'sessions' => TableSession::with('diningTable', 'orders', 'bill')
                ->whereIn('status', ['open', 'billing'])
                ->oldest('opened_at')
                ->get(),
            'games' => app(EntitlementService::class)->allows('games')
                ? GameSession::with('tableSession.diningTable')
                    ->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->orderBy('expires_at')
                    ->get()
                : collect(),
            'requests' => ServiceRequest::with('tableSession.diningTable')
                ->whereIn('status', ['pending', 'acknowledged'])
                ->oldest()
                ->get(),
        ]);
    }

    public function confirm(Request $request, Order $order, OrderWorkflowService $service, AuditService $audit)
    {
        $order = $service->transition($order, 'confirmed', $request->user()->id);
        $audit->record($request, 'order.confirmed', $order, null, ['status' => 'confirmed']);

        $message = app(EntitlementService::class)->allows('games')
            ? 'Order confirmed and game timer restarted.'
            : 'Order confirmed.';

        return back()->with('status', $message);
    }

    public function reject(Request $request, Order $order, OrderWorkflowService $service, AuditService $audit)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $order = $service->transition($order, 'rejected', $request->user()->id, $data['reason']);
        $audit->record($request, 'order.rejected', $order, null, [
            'status' => 'rejected',
            'reason' => $data['reason'],
        ]);

        return back()->with('status', 'Order rejected.');
    }

    public function bill(Request $request, TableSession $tableSession, BillingService $service, AuditService $audit)
    {
        $data = $request->validate(['discount_amount' => 'nullable|numeric|min:0']);
        $bill = $service->generate($tableSession, $request->user()->id, (float) ($data['discount_amount'] ?? 0));
        $audit->record($request, 'bill.generated', $bill, null, $bill->toArray());

        return back()->with('status', 'Bill generated: '.$bill->bill_number);
    }

    public function pay(Request $request, Bill $bill, BillingService $service, AuditService $audit)
    {
        $data = $request->validate([
            'received_amount' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);
        $payment = $service->payCash(
            $bill,
            $request->user()->id,
            (float) $data['received_amount'],
            $data['note'] ?? null,
        );
        $audit->record($request, 'payment.cash', $payment, null, $payment->toArray());

        return back()->with('status', 'Cash payment recorded. Change: '.$payment->change_amount);
    }

    public function request(Request $request, ServiceRequest $serviceRequest, AuditService $audit)
    {
        $data = $request->validate(['status' => 'required|in:acknowledged,completed,cancelled']);
        $serviceRequest->update($data + [
            'assigned_to' => $request->user()->id,
            'completed_at' => $data['status'] === 'completed' ? now() : null,
        ]);
        $audit->record($request, 'service_request.updated', $serviceRequest, null, $serviceRequest->toArray());

        return back()->with('status', 'Service request updated.');
    }

    public function extend(
        Request $request,
        GameSession $gameSession,
        GameAccessService $service,
        AuditService $audit,
    ) {
        $data = $request->validate(['minutes' => 'required|integer|min:1|max:240']);
        $before = $gameSession->toArray();
        $gameSession = $service->extend($gameSession, (int) $data['minutes']);
        $audit->record($request, 'game_session.extended', $gameSession, $before, [
            'minutes' => (int) $data['minutes'],
            'expires_at' => $gameSession->expires_at,
        ]);

        return back()->with('status', 'Game session extended by '.$data['minutes'].' minutes.');
    }

    public function stop(
        Request $request,
        GameSession $gameSession,
        GameAccessService $service,
        AuditService $audit,
    ) {
        $before = $gameSession->toArray();
        $gameSession = $service->stop($gameSession, $request->user()->id);
        $audit->record($request, 'game_session.stopped', $gameSession, $before, $gameSession->toArray());

        return back()->with('status', 'Games locked for the table.');
    }

    public function receipt(Bill $bill)
    {
        return view('counter.receipt-polished', [
            'bill' => $bill->load('payments', 'tableSession.diningTable', 'tableSession.orders.items'),
            'settings' => RestaurantSetting::first(),
        ]);
    }
}
