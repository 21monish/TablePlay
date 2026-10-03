<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Order extends Model { protected $guarded=[]; protected function casts(): array{return ['subtotal'=>'decimal:2','tax_amount'=>'decimal:2','discount_amount'=>'decimal:2','total_amount'=>'decimal:2','confirmed_at'=>'datetime'];} public function tableSession(){return $this->belongsTo(TableSession::class);} public function items(){return $this->hasMany(OrderItem::class);} public function statusLogs(){return $this->hasMany(OrderStatusLog::class);} }
