<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Bill extends Model { protected $guarded=[]; protected function casts(): array{return ['subtotal'=>'decimal:2','tax_amount'=>'decimal:2','discount_amount'=>'decimal:2','grand_total'=>'decimal:2','generated_at'=>'datetime','paid_at'=>'datetime'];} public function tableSession(){return $this->belongsTo(TableSession::class);} public function payments(){return $this->hasMany(Payment::class);} }
