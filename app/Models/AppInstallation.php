<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppInstallation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'update_available' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
