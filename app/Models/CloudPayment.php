<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudPayment extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['amount'=>'decimal:2','paid_at'=>'datetime','refunded_at'=>'datetime']; }
    public function restaurant() { return $this->belongsTo(CloudRestaurant::class, 'cloud_restaurant_id'); }
    public function invoice() { return $this->belongsTo(CloudInvoice::class, 'cloud_invoice_id'); }
    public function recordedBy() { return $this->belongsTo(User::class, 'recorded_by'); }
}
