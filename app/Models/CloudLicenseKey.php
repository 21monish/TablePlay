<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudLicenseKey extends Model
{
    protected $guarded = [];
    protected $hidden = ['key_hash'];
    protected function casts(): array { return ['is_active' => 'boolean', 'expires_at' => 'datetime', 'last_used_at' => 'datetime']; }
    public function subscription() { return $this->belongsTo(CloudSubscription::class, 'cloud_subscription_id'); }
}
