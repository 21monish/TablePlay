<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ServiceRequest extends Model { protected $guarded=[]; protected function casts(): array{return ['completed_at'=>'datetime'];} public function tableSession(){return $this->belongsTo(TableSession::class);} }
