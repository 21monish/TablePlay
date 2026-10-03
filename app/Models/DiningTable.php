<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DiningTable extends Model { protected $guarded=[]; protected function casts(): array{return ['is_active'=>'boolean'];} public function sessions(){return $this->hasMany(TableSession::class);} public function pairings(){return $this->hasMany(DevicePairing::class);} }
