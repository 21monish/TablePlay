<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\CarbonImmutable;

class CloudRestaurantFinancialReport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_month' => 'date', 'net_sales' => 'decimal:2', 'expenses' => 'decimal:2', 'revision' => 'integer'];
    }

    public function restaurant() { return $this->belongsTo(CloudRestaurant::class, 'cloud_restaurant_id'); }

    public function setPeriodMonthAttribute($value): void
    {
        // Keep DATE values date-only on SQLite/MySQL as well as PostgreSQL.
        $this->attributes['period_month'] = CarbonImmutable::parse($value)->toDateString();
    }

    public function submitter() { return $this->belongsTo(User::class, 'submitted_by')->withTrashed(); }

    public function getProfitCentsAttribute(): int
    {
        return (int) round((float) $this->net_sales * 100) - (int) round((float) $this->expenses * 100);
    }
}
