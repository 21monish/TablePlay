<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialPlan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'max_paired_tables' => 'integer',
            'trial_days' => 'integer',
            'grace_days' => 'integer',
            'monthly_price' => 'decimal:2',
            'annual_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(RestaurantSubscription::class);
    }

    public function cloudSubscriptions(): HasMany
    {
        return $this->hasMany(CloudSubscription::class);
    }

    public function getUsageCountAttribute(): int
    {
        return (int) ($this->subscriptions_count ?? $this->subscriptions()->count())
            + (int) ($this->cloud_subscriptions_count ?? $this->cloudSubscriptions()->count());
    }
}
