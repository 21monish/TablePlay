<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderStatusLog extends Model { public $timestamps=false; protected $guarded=[]; protected function casts(): array{return ['created_at'=>'datetime'];} public function order(){return $this->belongsTo(Order::class);} }
