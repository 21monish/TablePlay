<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TableSession extends Model { protected $guarded=[]; protected function casts(): array{return ['opened_at'=>'datetime','closed_at'=>'datetime'];} public function diningTable(){return $this->belongsTo(DiningTable::class);} public function orders(){return $this->hasMany(Order::class);} public function serviceRequests(){return $this->hasMany(ServiceRequest::class);} public function gameSessions(){return $this->hasMany(GameSession::class);} public function bill(){return $this->hasOne(Bill::class);} }
