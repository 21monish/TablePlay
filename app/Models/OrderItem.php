<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderItem extends Model { protected $guarded=[]; protected function casts(): array{return ['unit_price'=>'decimal:2','line_total'=>'decimal:2'];} public function order(){return $this->belongsTo(Order::class);} public function menuItem(){return $this->belongsTo(MenuItem::class);} }
