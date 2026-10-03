<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderWorkflowService;
use Illuminate\Http\Request;
class KitchenController {
 public function orders(){return Order::with('items','tableSession.diningTable')->whereIn('status',['confirmed','preparing','ready'])->oldest('confirmed_at')->get();}
 private function move(Request $r,Order $o,string $status,OrderWorkflowService $s){return $s->transition($o,$status,$r->user()->id);}
 public function preparing(Request $r,Order $order,OrderWorkflowService $s){return $this->move($r,$order,'preparing',$s);}
 public function ready(Request $r,Order $order,OrderWorkflowService $s){return $this->move($r,$order,'ready',$s);}
 public function served(Request $r,Order $order,OrderWorkflowService $s){return $this->move($r,$order,'served',$s);}
}
