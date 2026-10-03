<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudInvoice extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['subtotal'=>'decimal:2','tax_rate'=>'decimal:3','tax_amount'=>'decimal:2','total'=>'decimal:2','refunded_amount'=>'decimal:2','issued_on'=>'date','due_on'=>'date','paid_at'=>'datetime','voided_at'=>'datetime']; }
    public function restaurant() { return $this->belongsTo(CloudRestaurant::class, 'cloud_restaurant_id'); }
    public function subscription() { return $this->belongsTo(CloudSubscription::class, 'cloud_subscription_id'); }
    public function payments() { return $this->hasMany(CloudPayment::class); }
}
