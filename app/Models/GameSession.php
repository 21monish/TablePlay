<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class GameSession extends Model { protected $guarded=[]; protected function casts(): array{return ['started_at'=>'datetime','expires_at'=>'datetime','stopped_at'=>'datetime'];} public function tableSession(){return $this->belongsTo(TableSession::class);} public function triggerOrder(){return $this->belongsTo(Order::class,'trigger_order_id');} }
