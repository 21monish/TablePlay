<?php
namespace App\Services;
use App\Models\{AuditLog,Device,User};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
class AuditService { public function record(Request $request,string $action,?Model $entity=null,?array $old=null,?array $new=null): void { $actor=$request->user();AuditLog::create(['user_id'=>$actor instanceof User?$actor->id:null,'device_id'=>$actor instanceof Device?$actor->id:null,'action'=>$action,'entity_type'=>$entity?->getMorphClass(),'entity_id'=>$entity?->getKey(),'old_values'=>$old,'new_values'=>$new,'ip_address'=>$request->ip(),'created_at'=>now()]); } }
