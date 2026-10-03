<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CloudRenewalReminder extends Model { public $timestamps=false; protected $guarded=[]; protected function casts(): array{return ['expires_at'=>'datetime','created_at'=>'datetime'];} public function subscription(){return $this->belongsTo(CloudSubscription::class,'cloud_subscription_id');} }
