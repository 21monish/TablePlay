<?php

namespace App\Http\Controllers\Api;

use App\Events\TablePlayEvent;
use App\Http\Controllers\Controller;
use App\Models\{Category,DiningTable,MenuItem,Order,OrderItem,OrderStatusLog,RestaurantSetting,ServiceRequest,TableSession};
use App\Services\{EntitlementService,NumberService,OrderWorkflowService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WaiterController extends Controller
{
    public function dashboard(Request $request): array
    {
        return [
            'requests' => ServiceRequest::with('tableSession.diningTable')
                ->whereIn('status', ['pending', 'acknowledged'])
                ->where(fn ($query) => $query->whereNull('assigned_to')->orWhere('assigned_to', $request->user()->id))
                ->oldest()->get(),
            'ready_orders' => Order::with('items', 'tableSession.diningTable')->where('status', 'ready')->oldest('confirmed_at')->get(),
            'tables' => DiningTable::with(['sessions' => fn ($query) => $query->whereIn('status', ['open', 'billing'])->with('bill')])->where('is_active', true)->orderBy('table_code')->get(),
            'menu' => Category::where('is_active',true)->orderBy('sort_order')->with(['menuItems'=>fn($query)=>$query->where('is_active',true)->where('is_available',true)->orderBy('name')])->get(),
            'entitlements' => app(EntitlementService::class)->state(),
        ];
    }

    public function acknowledge(Request $request, ServiceRequest $serviceRequest): ServiceRequest
    {
        abort_unless(in_array($serviceRequest->status, ['pending', 'acknowledged'], true), 422, 'Request is already closed.');
        abort_if($serviceRequest->assigned_to && $serviceRequest->assigned_to !== $request->user()->id, 409, 'Request is assigned to another waiter.');
        $serviceRequest->update(['status' => 'acknowledged', 'assigned_to' => $request->user()->id]);
        $this->broadcastRequest($serviceRequest);
        return $serviceRequest->fresh('tableSession.diningTable');
    }

    public function complete(Request $request, ServiceRequest $serviceRequest): ServiceRequest
    {
        abort_unless(in_array($serviceRequest->status, ['pending', 'acknowledged'], true), 422, 'Request is already closed.');
        abort_if($serviceRequest->assigned_to && $serviceRequest->assigned_to !== $request->user()->id, 409, 'Request is assigned to another waiter.');
        $serviceRequest->update(['status' => 'completed', 'assigned_to' => $request->user()->id, 'completed_at' => now()]);
        $this->broadcastRequest($serviceRequest);
        return $serviceRequest->fresh('tableSession.diningTable');
    }

    public function served(Request $request, Order $order, OrderWorkflowService $workflow): Order
    {
        return $workflow->transition($order, 'served', $request->user()->id);
    }

    public function requestBill(Request $request, TableSession $tableSession): ServiceRequest
    {
        abort_unless(in_array($tableSession->status, ['open', 'billing'], true), 422, 'Table session is not active.');
        $serviceRequest = ServiceRequest::firstOrCreate([
            'table_session_id' => $tableSession->id,
            'request_type' => 'bill',
            'status' => 'pending',
        ], ['message' => 'Payment requested by waiter.']);
        TablePlayEvent::dispatch('ServiceRequestCreated', ['service_request' => $serviceRequest->toArray()], ['table.'.$tableSession->dining_table_id, 'counter']);
        return $serviceRequest->load('tableSession.diningTable');
    }

    public function openSession(Request $request, DiningTable $table): TableSession
    {
        app(EntitlementService::class)->assertCanOpenSession();
        $data = $request->validate(['guest_count' => ['required', 'integer', 'min:1', 'max:50']]);
        return DB::transaction(function () use ($request, $table, $data) {
            $table = DiningTable::lockForUpdate()->findOrFail($table->id);
            abort_if(TableSession::where('dining_table_id', $table->id)->whereIn('status', ['open', 'billing'])->exists(), 422, 'Table already has an active session.');
            abort_unless($table->is_active && $table->status !== 'disabled', 422, 'Table is not available for service.');
            $session = TableSession::create(['session_code' => 'PENDING', 'dining_table_id' => $table->id, 'guest_count' => $data['guest_count'], 'opened_by' => $request->user()->id, 'opened_at' => now(), 'status' => 'open']);
            $session->update(['session_code' => NumberService::make('SES', $session->id)]);
            $table->update(['status' => 'occupied']);
            return $session->fresh('diningTable');
        });
    }

    public function placeOrder(Request $request, TableSession $tableSession, EntitlementService $entitlements)
    {
        $entitlements->assertFeature('waiter_ordering');
        $data=$request->validate(['client_request_id'=>['required','uuid'],'items'=>['required','array','min:1'],'items.*.menu_item_id'=>['required','integer','exists:menu_items,id'],'items.*.quantity'=>['required','integer','min:1','max:50'],'items.*.special_instruction'=>['nullable','string','max:500'],'customer_note'=>['nullable','string','max:1000']]);
        if($existing=Order::where('client_request_id',$data['client_request_id'])->first())return response()->json($existing->load('items','tableSession.diningTable'));
        abort_unless($tableSession->status==='open',422,'Select an active table session before placing an order.');
        return DB::transaction(function()use($request,$tableSession,$data){
            $session=TableSession::lockForUpdate()->findOrFail($tableSession->id);abort_unless($session->status==='open',422,'Table session is not open.');
            $ids=collect($data['items'])->pluck('menu_item_id');$menu=MenuItem::whereIn('id',$ids)->where('is_active',true)->where('is_available',true)->get()->keyBy('id');abort_unless($menu->count()===$ids->unique()->count(),422,'A selected item is unavailable.');
            $subtotal=collect($data['items'])->sum(fn($item)=>(float)$menu[$item['menu_item_id']]->effective_price*$item['quantity']);$taxRate=(float)(RestaurantSetting::value('tax_rate')??0);$tax=round($subtotal*$taxRate/100,2);
            $order=Order::create(['order_number'=>'PENDING','table_session_id'=>$session->id,'order_sequence'=>$session->orders()->count()+1,'source'=>'waiter','placed_by'=>$request->user()->id,'client_request_id'=>$data['client_request_id'],'status'=>'pending','subtotal'=>$subtotal,'tax_amount'=>$tax,'discount_amount'=>0,'total_amount'=>$subtotal+$tax,'customer_note'=>$data['customer_note']??null]);$order->update(['order_number'=>NumberService::make('ORD',$order->id)]);
            foreach($data['items'] as $item){$dish=$menu[$item['menu_item_id']];$price=(float)$dish->effective_price;OrderItem::create(['order_id'=>$order->id,'menu_item_id'=>$dish->id,'item_name_snapshot'=>$dish->name,'unit_price'=>$price,'quantity'=>$item['quantity'],'line_total'=>$price*$item['quantity'],'special_instruction'=>$item['special_instruction']??null,'status'=>'pending']);}
            OrderStatusLog::create(['order_id'=>$order->id,'to_status'=>'pending','changed_by'=>$request->user()->id]);$order->load('items','tableSession.diningTable');DB::afterCommit(fn()=>TablePlayEvent::dispatch('OrderPlaced',['order'=>$order->toArray()],['table.'.$session->dining_table_id,'counter']));return response()->json($order,201);
        });
    }

    private function broadcastRequest(ServiceRequest $serviceRequest): void
    {
        TablePlayEvent::dispatch('ServiceRequestUpdated', ['service_request' => $serviceRequest->fresh()->toArray()], ['table.'.$serviceRequest->tableSession->dining_table_id, 'counter']);
    }
}
