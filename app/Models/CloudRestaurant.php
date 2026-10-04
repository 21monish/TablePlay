<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudRestaurant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trial_registered_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'welcome_email_sent_at' => 'datetime',
        ];
    }

    public function ownerUser()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(CloudSubscription::class);
    }

    public function invoices()
    {
        return $this->hasMany(CloudInvoice::class);
    }

    public function payments()
    {
        return $this->hasMany(CloudPayment::class);
    }
}
