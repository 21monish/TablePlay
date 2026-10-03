<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudSubscription extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'grace_ends_at' => 'datetime', 'scheduled_change_at' => 'datetime', 'license_revision' => 'integer'];
    }

    public function restaurant() { return $this->belongsTo(CloudRestaurant::class, 'cloud_restaurant_id'); }
    public function plan() { return $this->belongsTo(CommercialPlan::class, 'commercial_plan_id'); }
    public function licenseKeys() { return $this->hasMany(CloudLicenseKey::class); }
    public function installations() { return $this->hasMany(CloudInstallation::class); }
    public function events() { return $this->hasMany(CloudSubscriptionEvent::class); }
    public function invoices() { return $this->hasMany(CloudInvoice::class); }
    public function scheduledPlan() { return $this->belongsTo(CommercialPlan::class, 'scheduled_plan_id'); }
    public function reminders() { return $this->hasMany(CloudRenewalReminder::class); }
    public function offlineRequests() { return $this->hasMany(CloudOfflineActivationRequest::class); }
}
