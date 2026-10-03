<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudOfflineActivationRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'license_envelope' => 'array',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            'imported_at' => 'datetime',
            'issued_at' => 'datetime',
            'license_revision' => 'integer',
        ];
    }

    public function subscription() { return $this->belongsTo(CloudSubscription::class, 'cloud_subscription_id'); }
    public function installation() { return $this->belongsTo(CloudInstallation::class, 'cloud_installation_id'); }
    public function issuer() { return $this->belongsTo(User::class, 'issued_by'); }
}
