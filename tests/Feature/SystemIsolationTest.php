<?php
namespace Tests\Feature;
use App\Events\TablePlayEvent;
use App\Models\{Device,DiningTable,Order,RestaurantSetting,Role,TableSession,User};
use App\Services\{DevicePairingService,GameAccessService,OrderWorkflowService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class SystemIsolationTest extends TestCase {
 use RefreshDatabase;
 public function test_confirming_one_table_does_not_unlock_another_table(): void {
  Event::fake([TablePlayEvent::class]);RestaurantSetting::create(['restaurant_name'=>'TablePlay','game_duration_minutes'=>60]);$role=Role::create(['name'=>'counter','display_name'=>'Counter']);$counter=User::create(['role_id'=>$role->id,'name'=>'Counter','username'=>'counter','password'=>'secret-password','is_active'=>true]);
  $tables=collect([1,2])->map(fn($n)=>DiningTable::create(['table_code'=>'T0'.$n,'table_name'=>'Table '.$n,'capacity'=>4,'status'=>'occupied']));$sessions=$tables->map(fn($table)=>TableSession::create(['session_code'=>'SES-'.$table->id,'dining_table_id'=>$table->id,'guest_count'=>2,'opened_at'=>now(),'status'=>'open']));$orders=$sessions->map(fn($session)=>Order::create(['order_number'=>'ORD-'.$session->id,'table_session_id'=>$session->id,'order_sequence'=>1,'status'=>'pending','subtotal'=>100,'tax_amount'=>0,'discount_amount'=>0,'total_amount'=>100]));
  app(OrderWorkflowService::class)->transition($orders[0],'confirmed',$counter->id);$this->assertTrue(app(GameAccessService::class)->state($sessions[0])['unlocked']);$this->assertFalse(app(GameAccessService::class)->state($sessions[1])['unlocked']);Event::assertDispatched(TablePlayEvent::class,fn($event)=>$event->eventName==='OrderConfirmed');Event::assertDispatched(TablePlayEvent::class,fn($event)=>$event->eventName==='GameSessionStarted');
 }
 public function test_a_table_and_tablet_cannot_have_duplicate_active_pairings(): void {
  $table=DiningTable::create(['table_code'=>'T01','table_name'=>'Table 1','capacity'=>4]);$one=Device::create(['device_uuid'=>'10000000-0000-4000-8000-000000000001','device_name'=>'One','device_type'=>'tablet']);$two=Device::create(['device_uuid'=>'10000000-0000-4000-8000-000000000002','device_name'=>'Two','device_type'=>'tablet']);$service=app(DevicePairingService::class);$service->pair($one,$table);$this->expectException(ValidationException::class);$service->pair($two,$table);
 }
}
