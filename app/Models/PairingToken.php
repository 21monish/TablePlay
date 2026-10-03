<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PairingToken extends Model
{
    protected $guarded = [];
    protected $hidden = ['token_hash'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'used_at' => 'datetime']; }
    public function diningTable() { return $this->belongsTo(DiningTable::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function device() { return $this->belongsTo(Device::class, 'used_by_device_id'); }
}
