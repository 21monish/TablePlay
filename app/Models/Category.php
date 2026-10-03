<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Category extends Model { protected $guarded=[]; protected function casts(): array{return ['is_active'=>'boolean'];} public function menuItems(){return $this->hasMany(MenuItem::class);} }
