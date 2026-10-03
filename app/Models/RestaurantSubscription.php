<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RestaurantSubscription extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entitlement_snapshot' => 'array',
            'entitlement_limits' => 'array',
            'license_revision' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
            'offline_verification_due_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    public function plan() { return $this->belongsTo(CommercialPlan::class, 'commercial_plan_id'); }
}
