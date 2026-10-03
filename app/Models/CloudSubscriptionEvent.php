<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudSubscriptionEvent extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['effective_at' => 'datetime']; }
    public function subscription() { return $this->belongsTo(CloudSubscription::class, 'cloud_subscription_id'); }
    public function fromPlan() { return $this->belongsTo(CommercialPlan::class, 'from_plan_id'); }
    public function toPlan() { return $this->belongsTo(CommercialPlan::class, 'to_plan_id'); }
    public function user() { return $this->belongsTo(User::class, 'created_by'); }
}
