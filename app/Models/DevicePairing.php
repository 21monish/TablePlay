<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DevicePairing extends Model { protected $guarded=[]; protected function casts(): array{return ['is_active'=>'boolean','paired_at'=>'datetime','unpaired_at'=>'datetime'];} public function device(){return $this->belongsTo(Device::class);} public function diningTable(){return $this->belongsTo(DiningTable::class);} }
