<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class GameResult extends Model { public $timestamps=false; protected $guarded=[]; protected function casts(): array{return ['played_at'=>'datetime'];} }
