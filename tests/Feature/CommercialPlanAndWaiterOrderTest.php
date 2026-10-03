<?php
namespace Tests\Feature;
use App\Models\{Category,CommercialPlan,Device,DiningTable,MenuItem,RestaurantSubscription,Role,TableSession,User};
use App\Services\{DevicePairingService,EntitlementService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class CommercialPlanAndWaiterOrderTest extends TestCase {
 use RefreshDatabase;
 private function user(string $role): User{$r=Role::firstOrCreate(['name'=>$role],['display_name'=>ucfirst($role)]);return User::create(['role_id'=>$r->id,'name'=>ucfirst($role),'username'=>$role.Str::random(4),'password'=>'password123','is_active'=>true]);}
 public function test_superadmin_is_separate_and_can_activate_best_plan(): void{
  $super=$this->user('superadmin');$admin=$this->user('admin');
  $this->actingAs($super)->get('/superadmin')->assertOk();$this->actingAs($admin)->get('/superadmin')->assertForbidden();
  $best=CommercialPlan::where('slug','best')->firstOrFail();$this->actingAs($super)->post('/superadmin/activate',['plan_id'=>$best->id])->assertRedirect();
  $this->assertSame('best',app(EntitlementService::class)->state()['plan']['slug']);
 }
 public function test_best_plan_enforces_five_paired_table_limit(): void{
  app(EntitlementService::class)->activate(CommercialPlan::where('slug','best')->firstOrFail());$service=app(DevicePairingService::class);
  foreach(range(1,5) as $number){$table=DiningTable::create(['table_code'=>'T'.$number,'table_name'=>'Table '.$number,'capacity'=>4]);$device=Device::create(['device_uuid'=>(string)Str::uuid(),'device_name'=>'Tablet '.$number,'device_type'=>'tablet']);$service->pair($device,$table);}
  $this->expectException(ValidationException::class);$service->pair(Device::create(['device_uuid'=>(string)Str::uuid(),'device_name'=>'Tablet 6','device_type'=>'tablet']),DiningTable::create(['table_code'=>'T6','table_name'=>'Table 6','capacity'=>4]));
 }
 public function test_waiter_places_idempotent_order_for_selected_table(): void{
  $waiter=$this->user('waiter');$table=DiningTable::create(['table_code'=>'T1','table_name'=>'Table 1','capacity'=>4,'status'=>'occupied']);$session=TableSession::create(['session_code'=>'SES-1','dining_table_id'=>$table->id,'guest_count'=>2,'opened_by'=>$waiter->id,'opened_at'=>now(),'status'=>'open']);$category=Category::create(['name'=>'Main','sort_order'=>1]);$item=MenuItem::create(['category_id'=>$category->id,'name'=>'Paneer','price'=>200,'food_type'=>'veg','spice_level'=>'none','is_active'=>true,'is_available'=>true]);Sanctum::actingAs($waiter);$uuid=(string)Str::uuid();$payload=['client_request_id'=>$uuid,'items'=>[['menu_item_id'=>$item->id,'quantity'=>2]]];
  $this->postJson("/api/v1/waiter/sessions/{$session->id}/orders",$payload)->assertCreated()->assertJsonPath('source','waiter')->assertJsonPath('placed_by',$waiter->id)->assertJsonPath('total_amount','400.00');
  $this->postJson("/api/v1/waiter/sessions/{$session->id}/orders",$payload)->assertOk();$this->assertDatabaseCount('orders',1);
 }
}
