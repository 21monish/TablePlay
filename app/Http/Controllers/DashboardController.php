<?php
namespace App\Http\Controllers;
use App\Models\{DiningTable,GameSession,MenuItem,Order,ServiceRequest,User};
class DashboardController extends Controller {
 public function show(string $area='admin'){abort_unless(in_array($area,['admin','counter','kitchen'],true),404);return view('dashboard.show',['area'=>$area,'tables'=>DiningTable::with(['sessions'=>fn($q)=>$q->whereIn('status',['open','billing'])->with('orders')])->orderBy('table_code')->get(),'orders'=>Order::with('items','tableSession.diningTable')->whereIn('status',$area==='kitchen'?['confirmed','preparing','ready']:['pending','confirmed','preparing','ready'])->latest()->limit(20)->get(),'requests'=>ServiceRequest::with('tableSession.diningTable')->where('status','pending')->latest()->get(),'activeGames'=>GameSession::with('tableSession.diningTable')->where('status','active')->where('expires_at','>',now())->get(),'stats'=>['staff'=>User::count(),'tables'=>DiningTable::count(),'menu'=>MenuItem::where('is_active',true)->count(),'pending'=>Order::where('status','pending')->count()]]);}
}
