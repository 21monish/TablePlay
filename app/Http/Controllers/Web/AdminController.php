<?php
namespace App\Http\Controllers\Web;
use App\Http\Controllers\Controller;
use App\Models\{AuditLog,Category,Device,DevicePairing,DiningTable,Game,MenuItem,Order,Payment,RestaurantSetting,Role,ServiceRequest,TableSession,User};
use App\Services\{AuditService,DevicePairingService,PublicAssetStorage,SystemHealthService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;
class AdminController extends Controller {
 public function index(){
  $today=now()->toDateString();
  return view('admin.overview',[
   'stats'=>[
    'sales'=>Payment::whereDate('paid_at',$today)->sum('amount'),
    'orders'=>Order::whereDate('created_at',$today)->whereNotIn('status',['cancelled','rejected'])->count(),
    'open_tables'=>TableSession::whereIn('status',['open','billing'])->count(),
    'pending'=>Order::where('status','pending')->count(),
   ],
   'tables'=>DiningTable::with(['sessions'=>fn($q)=>$q->whereIn('status',['open','billing'])])->orderBy('table_code')->get(),
   'recentOrders'=>Order::with('items','tableSession.diningTable')->latest()->limit(7)->get(),
   'requests'=>ServiceRequest::with('tableSession.diningTable')->whereIn('status',['pending','acknowledged'])->oldest()->limit(6)->get(),
   'devices'=>Device::latest('last_seen_at')->limit(5)->get(),
  ]);
 }
 public function tables(){return view('admin.tables',['tables'=>DiningTable::with(['pairings'=>fn($q)=>$q->where('is_active',true)->with('device')])->withCount(['sessions','sessions as active_sessions_count'=>fn($q)=>$q->whereIn('status',['open','billing'])])->orderBy('table_code')->get(),'devices'=>Device::with(['pairings'=>fn($q)=>$q->where('is_active',true)->with('diningTable')])->latest()->get()]);}
 public function menu(){return view('admin.menu',['categories'=>Category::with(['menuItems'=>fn($q)=>$q->orderBy('name')])->orderBy('sort_order')->get()]);}
 public function team(){$roles=['admin','counter','kitchen','waiter'];return view('admin.team',['roles'=>Role::whereIn('name',$roles)->orderBy('name')->get(),'users'=>User::with('role')->whereHas('role',fn($q)=>$q->whereIn('name',$roles))->orderBy('name')->get()]);}
 public function games(){return view('admin.games',['games'=>Game::orderBy('sort_order')->get()]);}
 public function reports(){return view('admin.reports',['sales'=>Order::whereNotIn('status',['cancelled','rejected'])->selectRaw('date(created_at) sale_date, count(*) orders_count, sum(total_amount) total')->groupBy('sale_date')->latest('sale_date')->limit(30)->get(),'audits'=>AuditLog::with('user')->latest()->limit(100)->get(),'summary'=>['revenue'=>Payment::sum('amount'),'payments'=>Payment::count(),'orders'=>Order::whereNotIn('status',['cancelled','rejected'])->count(),'average'=>Order::whereNotIn('status',['cancelled','rejected'])->avg('total_amount')??0]]);}
 public function settings(){return view('admin.settings',['settings'=>RestaurantSetting::first()]);}
 public function system(SystemHealthService $health){return view('admin.system',['health'=>$health->snapshot()]);}
 public function health(SystemHealthService $health){return response()->json($health->snapshot());}
 public function storeUser(Request $r,AuditService $audit){
  $r->merge(['name'=>trim((string)$r->input('name')),'username'=>trim((string)$r->input('username')),'email'=>$r->filled('email')?strtolower(trim((string)$r->input('email'))):null,'mobile'=>$r->filled('mobile')?trim((string)$r->input('mobile')):null,'pin'=>$r->filled('pin')?trim((string)$r->input('pin')):null]);
  $selectedRole=Role::query()->find($r->input('role_id'));
  $emailRequired=(bool)config('tableplay.require_privileged_email_verification')&&$selectedRole?->name==='admin';
  $d=$r->validate(['role_id'=>['required',Rule::exists('roles','id')->where(fn($q)=>$q->whereIn('name',['admin','counter','kitchen','waiter']))],'name'=>'required|string|max:255','username'=>['required','string','min:3','max:100','regex:/^[A-Za-z0-9._-]+$/','unique:users'],'email'=>[Rule::requiredIf($emailRequired),'nullable','email:rfc','max:255','unique:users'],'mobile'=>'nullable|string|max:30','password'=>'required|string|min:8|max:255','pin'=>'nullable|digits_between:4,12']);
  $user=User::create($d+['is_active'=>true]);
  $audit->record($r,'user.created',$user,null,$user->only(['role_id','name','username','email','mobile','is_active']));
  if($user->loadMissing('role')->requiresEmailVerification()){
   try{$user->sendEmailVerificationNotification();}catch(Throwable $exception){report($exception);return back()->with('warning','Account created, but the verification email could not be sent. Check the mail settings and resend it.');}
   return back()->with('status','Staff account created. A verification link was sent to '.$user->email.'.');
  }
  return back()->with('status','Staff account created.');
 }
 public function toggleUser(Request $r,User $user,AuditService $audit){abort_unless($user->loadMissing('role')->hasRole('admin','counter','kitchen','waiter'),403,'Platform accounts cannot be managed from a restaurant workspace.');abort_if($user->is($r->user()),422,'You cannot disable your own account.');$old=$user->only('is_active');$user->update(['is_active'=>!$user->is_active]);$audit->record($r,'user.toggled',$user,$old,$user->only('is_active'));return back()->with('status','Staff status updated.');}
 public function storeTable(Request $r,AuditService $audit){$d=$r->validate(['table_code'=>['required','string','max:30','regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/','unique:dining_tables'],'table_name'=>'required|string|max:100','capacity'=>'required|integer|min:1|max:50']);$table=DiningTable::create($d+['status'=>'available','is_active'=>true]);$audit->record($r,'table.created',$table,null,$table->toArray());return back()->with('status','Table created.');}
 public function updateTable(Request $r,DiningTable $table,AuditService $audit){
  $d=$r->validate(['table_name'=>'required|string|max:100','capacity'=>'required|integer|min:1|max:50','status'=>'required|in:available,occupied,reserved,cleaning,disabled','is_active'=>'nullable|boolean']);
  $disabling=!$r->boolean('is_active')||$d['status']==='disabled';
  if($disabling&&$table->sessions()->whereIn('status',['open','billing'])->exists())throw ValidationException::withMessages(['table'=>'Close the active table session before disabling this table.']);
  DB::transaction(function()use($r,$table,$audit,$d,$disabling){
   $old=$table->toArray();
   $table->update(array_merge($d,['status'=>$disabling?'disabled':$d['status'],'is_active'=>!$disabling]));
   if($disabling)$this->unpairTable($table);
   $audit->record($r,'table.updated',$table,$old,$table->fresh()->toArray());
  });
  return back()->with('status',$disabling?'Table disabled and removed from service.':'Table updated.');
 }
 public function destroyTable(Request $r,DiningTable $table,AuditService $audit){
  if($table->sessions()->whereIn('status',['open','billing'])->exists())throw ValidationException::withMessages(['table'=>'This table is currently serving guests. Close its active session before removing it.']);
  $hasHistory=$table->sessions()->exists();
  DB::transaction(function()use($r,$table,$audit,$hasHistory){
   $old=$table->toArray();
   $this->unpairTable($table);
   if($hasHistory){
    $table->update(['status'=>'disabled','is_active'=>false]);
    $audit->record($r,'table.archived',$table,$old,$table->fresh()->toArray());
   }else{
    $audit->record($r,'table.deleted',$table,$old,null);
    $table->delete();
   }
  });
  return back()->with('status',$hasHistory?'Table has service history, so it was archived safely.':'Unused table permanently deleted.');
 }
 private function unpairTable(DiningTable $table): void{$table->pairings()->where('is_active',true)->update(['is_active'=>false,'unpaired_at'=>now(),'updated_at'=>now()]);}
 public function pairDevice(Request $r,DevicePairingService $service,AuditService $audit){$d=$r->validate(['device_id'=>'required|exists:devices,id','dining_table_id'=>'required|exists:dining_tables,id']);$pairing=$service->pair(Device::findOrFail($d['device_id']),DiningTable::findOrFail($d['dining_table_id']),$r->user()->id);$audit->record($r,'device.paired',$pairing,null,$pairing->toArray());return back()->with('status','Tablet paired.');}
 public function unpairDevice(Request $r,DevicePairing $pairing,AuditService $audit){abort_unless($pairing->is_active,422);$old=$pairing->toArray();$pairing->update(['is_active'=>false,'unpaired_at'=>now()]);$audit->record($r,'device.unpaired',$pairing,$old,$pairing->fresh()->toArray());return back()->with('status','Tablet unpaired.');}
 public function storeCategory(Request $r,AuditService $audit){$category=Category::create($r->validate(['name'=>'required|string|max:100','description'=>'nullable|string|max:1000','sort_order'=>'required|integer|min:0|max:10000'])+['is_active'=>true]);$audit->record($r,'category.created',$category,null,$category->toArray());return back()->with('status','Category created.');}
 public function storeMenuItem(Request $r,AuditService $audit,PublicAssetStorage $assets){
  $d=$r->validate([
   'category_id'=>'required|exists:categories,id','name'=>'required|string|max:150','description'=>'nullable|string|max:3000',
   'short_description'=>'nullable|string|max:180','ingredients'=>'nullable|string|max:2000','allergens'=>'nullable|string|max:1000',
   'spice_level'=>'required|in:none,mild,medium,hot','calories'=>'nullable|integer|min:0|max:5000',
   'price'=>'required|numeric|min:0|max:99999999.99','discount_price'=>'nullable|numeric|min:0|max:99999999.99|lte:price','food_type'=>'required|in:veg,non_veg,egg,other',
   'preparation_minutes'=>'nullable|integer|min:1|max:240','is_recommended'=>'nullable|boolean','is_bestseller'=>'nullable|boolean',
   'customizations'=>'nullable|json','image'=>'nullable|image|mimes:png,jpg,jpeg,webp|max:5120',
  ]);
  $d['allergens']=collect(explode(',',$d['allergens']??''))->map(fn($value)=>trim($value))->filter()->values()->all();
  $d['customizations']=isset($d['customizations'])?json_decode($d['customizations'],true,flags:JSON_THROW_ON_ERROR):[];
  $d['is_recommended']=$r->boolean('is_recommended');$d['is_bestseller']=$r->boolean('is_bestseller');
  if($r->hasFile('image')){$d['image_path']=$assets->store($r->file('image'),'menu-items');}
  unset($d['image']);
  $item=MenuItem::create($d+['is_available'=>true,'is_active'=>true]);$audit->record($r,'menu_item.created',$item,null,$item->toArray());return back()->with('status','Menu item created.');
 }
 public function toggleMenuItem(Request $r,MenuItem $menuItem,AuditService $audit){$old=$menuItem->only('is_available');$menuItem->update(['is_available'=>!$menuItem->is_available]);$audit->record($r,'menu_item.availability',$menuItem,$old,$menuItem->only('is_available'));return back()->with('status','Availability updated.');}
 public function updateMenuItem(Request $r,MenuItem $menuItem,AuditService $audit,PublicAssetStorage $assets){
  $d=$r->validate([
   'category_id'=>'required|exists:categories,id','name'=>'required|string|max:150','description'=>'nullable|string|max:3000',
   'short_description'=>'nullable|string|max:180','ingredients'=>'nullable|string|max:2000','allergens'=>'nullable|string|max:1000',
   'spice_level'=>'required|in:none,mild,medium,hot','calories'=>'nullable|integer|min:0|max:5000',
   'price'=>'required|numeric|min:0|max:99999999.99','discount_price'=>'nullable|numeric|min:0|max:99999999.99|lte:price','food_type'=>'required|in:veg,non_veg,egg,other',
   'preparation_minutes'=>'nullable|integer|min:1|max:240','is_recommended'=>'nullable|boolean','is_bestseller'=>'nullable|boolean',
   'customizations'=>'nullable|json','image'=>'nullable|image|mimes:png,jpg,jpeg,webp|max:5120','remove_image'=>'nullable|boolean',
  ]);
  $old=$menuItem->toArray();$oldImage=$menuItem->image_path;
  $d['allergens']=collect(explode(',',$d['allergens']??''))->map(fn($value)=>trim($value))->filter()->values()->all();
  $d['customizations']=filled($d['customizations']??null)?json_decode($d['customizations'],true,flags:JSON_THROW_ON_ERROR):[];
  $d['is_recommended']=$r->boolean('is_recommended');$d['is_bestseller']=$r->boolean('is_bestseller');
  if($r->hasFile('image'))$d['image_path']=$assets->store($r->file('image'),'menu-items');
  elseif($r->boolean('remove_image'))$d['image_path']=null;
  unset($d['image'],$d['remove_image']);
  $menuItem->update($d);
  if($oldImage&&$oldImage!==$menuItem->image_path)$assets->delete($oldImage);
  $audit->record($r,'menu_item.updated',$menuItem,$old,$menuItem->fresh()->toArray());
  return back()->with('status','Menu item and photo updated.');
 }
 public function updateSettings(Request $r,AuditService $audit,PublicAssetStorage $assets){
  $d=$r->validate(['restaurant_name'=>'required|string|max:255','tagline'=>'nullable|string|max:120','address'=>'nullable|string|max:1000','phone'=>'nullable|string|max:30','email'=>'nullable|email|max:255','gstin'=>'nullable|string|max:30','brand_color'=>['required','regex:/^#[0-9A-Fa-f]{6}$/'],'secondary_color'=>['nullable','regex:/^#[0-9A-Fa-f]{6}$/'],'timezone'=>'required|timezone','currency'=>'required|alpha|size:3','tax_name'=>'required|string|max:50','tax_rate'=>'required|numeric|min:0|max:100','game_duration_minutes'=>'required|integer|min:1|max:240','kitchen_refresh_seconds'=>'required|integer|min:2|max:30','device_offline_minutes'=>'required|integer|min:1|max:60','receipt_footer'=>'nullable|string|max:1000','restaurant_logo'=>'nullable|image|mimes:png,jpg,jpeg,webp|max:5120','app_logo'=>'nullable|image|mimes:png,jpg,jpeg,webp|max:5120']);
  $settings=RestaurantSetting::firstOrCreate(['id'=>1],collect($d)->except(['restaurant_logo','app_logo'])->all());$old=$settings->toArray();
  if($r->hasFile('restaurant_logo')){$d['restaurant_logo_path']=$assets->store($r->file('restaurant_logo'),'branding');}unset($d['restaurant_logo']);
  if($r->hasFile('app_logo')){$path=$assets->store($r->file('app_logo'),'branding');$d['customer_app_logo_path']=$path;$d['staff_app_logo_path']=$path;$d['system_logo_path']=$path;$d['favicon_path']=$path;}unset($d['app_logo']);
  $settings->update($d);$audit->record($r,'settings.updated',$settings,$old,$settings->fresh()->toArray());return back()->with('status','Restaurant settings and branding saved.');
 }
 public function storeGame(Request $r,AuditService $audit){$d=$r->validate(['name'=>'required|string|max:100','slug'=>'required|alpha_dash|unique:games','description'=>'nullable|string|max:1000','player_mode'=>'required|in:one,two,four','game_path'=>['required','string','max:255','regex:/^\/games\/[A-Za-z0-9._\/-]+$/'],'sort_order'=>'required|integer|min:0|max:10000']);$game=Game::create($d+['is_active'=>true]);$audit->record($r,'game.created',$game,null,$game->toArray());return back()->with('status','Game added.');}
 public function toggleGame(Request $r,Game $game,AuditService $audit){$old=$game->only('is_active');$game->update(['is_active'=>!$game->is_active]);$audit->record($r,'game.toggled',$game,$old,$game->only('is_active'));return back()->with('status','Game status updated.');}
}
