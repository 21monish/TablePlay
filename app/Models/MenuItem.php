<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MenuItem extends Model
{
    protected $guarded = [];

    protected $appends = ['image_url', 'effective_price'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'allergens' => 'array',
            'customizations' => 'array',
            'preparation_minutes' => 'integer',
            'calories' => 'integer',
            'is_available' => 'boolean',
            'is_recommended' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) return null;
        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) return $this->image_path;
        return asset(ltrim($this->image_path, '/'));
    }

    public function getEffectivePriceAttribute(): string
    {
        return number_format((float) ($this->discount_price ?? $this->price), 2, '.', '');
    }

    public function category(){return $this->belongsTo(Category::class);}
}
