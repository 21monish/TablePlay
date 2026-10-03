<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model { protected $guarded=[]; protected function casts(): array{return ['amount'=>'decimal:2','received_amount'=>'decimal:2','change_amount'=>'decimal:2','paid_at'=>'datetime'];} public function bill(){return $this->belongsTo(Bill::class);} }
