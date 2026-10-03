<?php

namespace App\Http\Controllers\Api;

use App\Events\TablePlayEvent;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\GameSession;
use App\Models\Order;
use App\Models\ServiceRequest as ServiceRequestModel;
use App\Models\TableSession;
use App\Services\BillingService;
use App\Services\EntitlementService;
use App\Services\GameAccessService;
use App\Services\OrderWorkflowService;
use Illuminate\Http\Request;

class CounterController extends Controller
{
    public function dashboard()
    {
        return [
            'entitlements' => app(EntitlementService::class)->state(),
            'orders' => $this->orders(),
            'sessions' => TableSession::with('diningTable', 'orders.items', 'bill')
                ->whereIn('status', ['open', 'billing'])
                ->oldest('opened_at')
                ->get(),
            'service_requests' => $this->serviceRequests(),
            'game_sessions' => app(EntitlementService::class)->allows('games')
                ? GameSession::with('tableSession.diningTable')
                    ->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->orderBy('expires_at')
                    ->get()
                : collect(),
        ];
    }

    public function orders()
    {
        return Order::with('items', 'tableSession.diningTable')
            ->whereIn('status', ['pending', 'confirmed'])
            ->oldest()
            ->get();
    }

    public function confirm(Request $request, Order $order, OrderWorkflowService $service)
    {
        return $service->transition($order, 'confirmed', $request->user()->id);
    }

    public function reject(Request $request, Order $order, OrderWorkflowService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return $service->transition($order, 'rejected', $request->user()->id, $data['reason']);
    }

    public function extend(Request $request, GameSession $session, GameAccessService $service)
    {
        $data = $request->validate(['minutes' => 'required|integer|min:1|max:240']);

        return $service->extend($session, (int) $data['minutes']);
    }

    public function stop(Request $request, GameSession $session, GameAccessService $service)
    {
        return $service->stop($session, $request->user()->id);
    }

    public function bill(Request $request, BillingService $service)
    {
        $data = $request->validate([
            'table_session_id' => 'required|exists:table_sessions,id',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        return response()->json($service->generate(
            TableSession::findOrFail($data['table_session_id']),
            $request->user()->id,
            (float) ($data['discount_amount'] ?? 0),
        ), 201);
    }

    public function cashPayment(Request $request, Bill $bill, BillingService $service)
    {
        $data = $request->validate([
            'received_amount' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        return response()->json($service->payCash(
            $bill,
            $request->user()->id,
            (float) $data['received_amount'],
            $data['note'] ?? null,
        ), 201);
    }

    public function serviceRequests()
    {
        return ServiceRequestModel::with('tableSession.diningTable')
            ->whereIn('status', ['pending', 'acknowledged'])
            ->oldest()
            ->get();
    }

    public function updateServiceRequest(Request $request, ServiceRequestModel $serviceRequest)
    {
        $data = $request->validate([
            'status' => 'required|in:acknowledged,completed,cancelled',
            'assigned_to' => 'nullable|exists:users,id',
        ]);
        $serviceRequest->update($data + [
            'completed_at' => $data['status'] === 'completed' ? now() : null,
        ]);
        TablePlayEvent::dispatch(
            'ServiceRequestUpdated',
            ['service_request' => $serviceRequest->fresh()->toArray()],
            ['table.'.$serviceRequest->tableSession->dining_table_id, 'counter'],
        );

        return $serviceRequest->fresh();
    }
}
