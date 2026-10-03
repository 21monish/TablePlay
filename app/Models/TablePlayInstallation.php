<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TablePlayInstallation extends Model
{
    protected $table = 'tableplay_installations';

    protected $guarded = [];

    protected $hidden = ['activation_token', 'offline_request_private_key'];

    protected function casts(): array
    {
        return [
            'activation_token' => 'encrypted',
            'offline_request_private_key' => 'encrypted',
            'offline_request_generated_at' => 'datetime',
            'license_revision' => 'integer',
            'last_license_issued_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }
}
