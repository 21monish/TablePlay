<?php
namespace Tests\Feature;
use App\Models\{Category,DiningTable,MenuItem,Order,OrderItem,Role,TableSession,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class KitchenDisplayTest extends TestCase {
 use RefreshDatabase;
 public function test_kitchen_can_view_instructions_and_progress_an_order(): void {
  $role=Role::create(['name'=>'kitchen','display_name'=>'Kitchen']);$user=User::create(['role_id'=>$role->id,'name'=>'Chef','username'=>'chef','password'=>'secret-password','is_active'=>true]);
  $table=DiningTable::create(['table_code'=>'T01','table_name'=>'Table 1','capacity'=>4,'status'=>'occupied']);$session=TableSession::create(['session_code'=>'SES-1','dining_table_id'=>$table->id,'guest_count'=>2,'opened_at'=>now(),'status'=>'open']);
  $category=Category::create(['name'=>'Mains']);$menu=MenuItem::create(['category_id'=>$category->id,'name'=>'Biryani','price'=>200,'food_type'=>'veg','preparation_minutes'=>20]);
  $order=Order::create(['order_number'=>'ORD-1','table_session_id'=>$session->id,'order_sequence'=>1,'status'=>'confirmed','subtotal'=>200,'tax_amount'=>0,'discount_amount'=>0,'total_amount'=>200,'confirmed_by'=>$user->id,'confirmed_at'=>now()]);
  OrderItem::create(['order_id'=>$order->id,'menu_item_id'=>$menu->id,'item_name_snapshot'=>'Biryani','unit_price'=>200,'quantity'=>1,'line_total'=>200,'special_instruction'=>'No onion','status'=>'pending']);
  $this->actingAs($user)->get('/kitchen')->assertOk()->assertSee('No onion')->assertSee('Accept & start preparing',false);
  $this->actingAs($user)->post('/kitchen/orders/'.$order->id.'/preparing')->assertRedirect();$this->assertDatabaseHas('orders',['id'=>$order->id,'status'=>'preparing']);
 }
}
