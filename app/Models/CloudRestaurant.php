<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudRestaurant extends Model
{
    protected $guarded = [];

    public function subscriptions() { return $this->hasMany(CloudSubscription::class); }
    public function invoices() { return $this->hasMany(CloudInvoice::class); }
    public function payments() { return $this->hasMany(CloudPayment::class); }
}
