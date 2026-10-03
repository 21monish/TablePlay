<?php
namespace App\Http\Controllers\Web;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\{AuditService,OrderWorkflowService};
use Illuminate\Http\Request;
class KitchenController extends Controller {
 private function query(){return Order::with('items.menuItem','tableSession.diningTable')->whereIn('status',['confirmed','preparing','ready'])->oldest('confirmed_at');}
 public function index(){return view('kitchen.board',['orders'=>$this->query()->get()]);}
 public function snapshot(){return $this->query()->get()->map(fn($order)=>['id'=>$order->id,'status'=>$order->status,'updated_at'=>$order->updated_at?->timestamp]);}
 private function move(Request $r,Order $order,string $status,OrderWorkflowService $service,AuditService $audit){$order=$service->transition($order,$status,$r->user()->id);$audit->record($r,'kitchen.order.'.$status,$order,null,['status'=>$status]);return back()->with('status','Order marked '.ucfirst($status).'.');}
 public function preparing(Request $r,Order $order,OrderWorkflowService $service,AuditService $audit){return $this->move($r,$order,'preparing',$service,$audit);}
 public function ready(Request $r,Order $order,OrderWorkflowService $service,AuditService $audit){return $this->move($r,$order,'ready',$service,$audit);}
 public function served(Request $r,Order $order,OrderWorkflowService $service,AuditService $audit){return $this->move($r,$order,'served',$service,$audit);}
}
