<?php
namespace App\Services;
use App\Events\TablePlayEvent;
use App\Models\{Order,OrderStatusLog};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class OrderWorkflowService {
 private const NEXT=['pending'=>['confirmed','rejected','cancelled'],'confirmed'=>['preparing','cancelled'],'preparing'=>['ready'],'ready'=>['served']];
 public function transition(Order $order,string $to,?int $userId=null,?string $note=null): Order { return DB::transaction(function() use($order,$to,$userId,$note){ $order=Order::lockForUpdate()->findOrFail($order->id); if(!in_array($to,self::NEXT[$order->status]??[],true)) throw ValidationException::withMessages(['status'=>"Cannot move order from {$order->status} to {$to}."]); if(in_array($to,['cancelled','rejected'],true)&&blank($note)) throw ValidationException::withMessages(['reason'=>'A reason is required.']); $from=$order->status; $values=['status'=>$to]; if($to==='confirmed'){$values['confirmed_by']=$userId;$values['confirmed_at']=now();} if(in_array($to,['cancelled','rejected'],true)){$values['cancelled_by']=$userId;$values['cancel_reason']=$note;} $order->update($values); if(in_array($to,['preparing','ready','served','cancelled'],true))$order->items()->where('status','!=','cancelled')->update(['status'=>$to]); OrderStatusLog::create(['order_id'=>$order->id,'from_status'=>$from,'to_status'=>$to,'changed_by'=>$userId,'note'=>$note]); if($to==='confirmed'&&app(EntitlementService::class)->allows('games'))app(GameAccessService::class)->restartFor($order); $order=$order->fresh(['items','tableSession.diningTable']); $eventName=match($to){'confirmed'=>'OrderConfirmed','rejected'=>'OrderRejected',default=>'OrderStatusChanged'}; DB::afterCommit(fn()=>TablePlayEvent::dispatch($eventName,['order'=>$order->toArray(),'from_status'=>$from,'to_status'=>$to],['table.'.$order->tableSession->dining_table_id,'counter','kitchen'])); return $order; }); }
}
