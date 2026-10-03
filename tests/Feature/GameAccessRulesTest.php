<?php
namespace Tests\Feature;
use App\Models\{CommercialPlan,DiningTable,Order,RestaurantSetting,Role,TableSession,User};
use App\Services\{EntitlementService,GameAccessService,OrderWorkflowService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
class GameAccessRulesTest extends TestCase {
 use RefreshDatabase;
 public function test_each_confirmation_restarts_sixty_minutes_and_rejection_does_not_unlock(): void {
  Carbon::setTestNow('2026-08-24 12:00:00');app(EntitlementService::class)->activate(CommercialPlan::where('slug','best')->firstOrFail());$role=Role::create(['name'=>'counter','display_name'=>'Counter']);$user=User::create(['role_id'=>$role->id,'name'=>'Counter','username'=>'counter','password'=>'secret-password','is_active'=>true]);RestaurantSetting::create(['restaurant_name'=>'TablePlay','game_duration_minutes'=>60]);$table=DiningTable::create(['table_code'=>'T01','table_name'=>'Table 1','capacity'=>4,'status'=>'occupied']);$session=TableSession::create(['session_code'=>'SES-1','dining_table_id'=>$table->id,'guest_count'=>2,'opened_at'=>now(),'status'=>'open']);
  $make=fn($n)=>Order::create(['order_number'=>'ORD-'.$n,'table_session_id'=>$session->id,'order_sequence'=>$n,'status'=>'pending','subtotal'=>100,'tax_amount'=>0,'discount_amount'=>0,'total_amount'=>100]);$workflow=app(OrderWorkflowService::class);$access=app(GameAccessService::class);
  $first=$make(1);$workflow->transition($first,'confirmed',$user->id);$this->assertSame('2026-08-24 13:00:00',$session->gameSessions()->where('status','active')->first()->expires_at->format('Y-m-d H:i:s'));
  Carbon::setTestNow('2026-08-24 12:30:00');$second=$make(2);$workflow->transition($second,'confirmed',$user->id);$this->assertDatabaseHas('game_sessions',['trigger_order_id'=>$first->id,'status'=>'expired']);$this->assertSame('2026-08-24 13:30:00',$session->gameSessions()->where('status','active')->first()->expires_at->format('Y-m-d H:i:s'));
  $third=$make(3);$workflow->transition($third,'rejected',$user->id,'Unavailable');$this->assertSame($second->id,$access->state($session)['session']->trigger_order_id);
  Carbon::setTestNow('2026-08-24 13:31:00');$this->assertFalse($access->state($session)['unlocked']);Carbon::setTestNow();
 }
}
