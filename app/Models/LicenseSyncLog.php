<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseSyncLog extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['metadata' => 'array']; }
}
