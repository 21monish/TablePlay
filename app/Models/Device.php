<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
class Device extends Authenticatable { use HasApiTokens; protected $guarded=[]; protected $hidden=['auth_token_hash']; protected function casts(): array{return ['is_active'=>'boolean','last_seen_at'=>'datetime'];} public function pairings(){return $this->hasMany(DevicePairing::class);} }
