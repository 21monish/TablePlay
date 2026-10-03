<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppRelease extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mandatory' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'verified_at' => 'datetime',
            'rollout_percentage' => 'integer',
        ];
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
