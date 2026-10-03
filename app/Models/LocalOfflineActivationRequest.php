<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalOfflineActivationRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'request_document' => 'array',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }
}
