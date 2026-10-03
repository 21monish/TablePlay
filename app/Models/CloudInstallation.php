<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudInstallation extends Model
{
    protected $guarded = [];
    protected $hidden = ['activation_token_hash'];
    protected function casts(): array { return ['activated_at' => 'datetime', 'last_seen_at' => 'datetime', 'deactivated_at' => 'datetime', 'request_generated_at' => 'datetime', 'license_revision' => 'integer']; }
    public function subscription() { return $this->belongsTo(CloudSubscription::class, 'cloud_subscription_id'); }
    public function offlineRequests() { return $this->hasMany(CloudOfflineActivationRequest::class); }
}
